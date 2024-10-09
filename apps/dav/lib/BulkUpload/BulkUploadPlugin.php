<?php
/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCA\DAV\BulkUpload;

use OCA\DAV\Connector\Sabre\MtimeSanitizer;
use OCP\AppFramework\Http;
use OCP\Files\DavUtil;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class BulkUploadPlugin extends ServerPlugin {
	private Folder $userFolder;
	private LoggerInterface $logger;

	public function __construct(
		Folder $userFolder,
		LoggerInterface $logger,
	) {
		$this->userFolder = $userFolder;
		$this->logger = $logger;
	}

	/**
	 * Register listener on POST requests with the httpPost method.
	 */
	public function initialize(Server $server): void {
		$server->on('method:POST', [$this, 'httpPost'], 10);
	}

	private function checkHashes($content, $headers) {
		// handle https://www.php.net/manual/fr/function.hash-algos.php automatically
		$headers["x-file-md5"];
		$context = hash_init('md5');
		hash_update_stream($context, $this->stream, $length);
		fseek($this->stream, -$length, SEEK_CUR);
		return hash_final($context);

		// we need at least one hash, don't enforce md5 specifically
		if (!isset($headers["x-file-md5"])) {
			throw new BadRequest("The X-File-MD5 header must not be null.");
		}
		if ($md5 !== $computedMd5) {
			throw new BadRequest("Computed md5 hash is incorrect.");
		}
	}

	/**
	 * Handle POST requests on /dav/bulk
	 * - parsing is done with a MultipartContentsParser object
	 * - writing is done with the userFolder service
	 *
	 * Will respond with an object containing an ETag for every written files.
	 */
	public function httpPost(RequestInterface $request, ResponseInterface $response): bool {
		// Limit bulk upload to the /dav/bulk endpoint
		if ($request->getPath() !== 'bulk') {
			return true;
		}

		$multiPartParser = new MultipartRequestParser($request, $this->logger);
		$writtenFiles = [];

		while (!$multiPartParser->isAtLastBoundary()) {
			try {
				[$headers, $content] = $multiPartParser->parseNextPart();
			} catch (\Exception $e) {
				// Return early if an error occurs during parsing.
				$this->logger->error($e->getMessage());
				$response->setStatus(Http::STATUS_BAD_REQUEST); // TODO: sending BAD REQUEST indiscriminatly is a bit offensive toward clients, we should 500 unless we know it's the client's fault
				$response->setBody(json_encode($writtenFiles, JSON_THROW_ON_ERROR));
				// TODO: maybe report status for the parts that were successful ?
				return false;
			}

			// TODO: maybe try to hijack sabre from there ? Leveraging exactly the same mechanism as for other upload path ?
			try {
				// TODO: Remove 'x-file-mtime' when the desktop client no longer use it.
				if (isset($headers['x-file-mtime'])) {
					$mtime = MtimeSanitizer::sanitizeMtime($headers['x-file-mtime']);
				} elseif (isset($headers['x-oc-mtime'])) {
					$mtime = MtimeSanitizer::sanitizeMtime($headers['x-oc-mtime']);
				} else {
					$mtime = null;
				}

				// TODO: this is the moment to check the hash
				// TODO: maybe store in upload then move (use the same process as for other uploads) => less chance to corrupt something during a move than an override (at least on most storage backend)
				$node = $this->userFolder->newFile($headers['x-file-path'], $content);
				$node->touch($mtime);
				$node = $this->userFolder->getFirstNodeById($node->getId());

				// TODO: check that we include as much as for other upload mechanism, maybe reuse some stuff
				$writtenFiles[$headers['x-file-path']] = [
					'error' => false,
					'etag' => $node->getETag(),
					'fileid' => DavUtil::getDavFileId($node->getId()),
					'permissions' => DavUtil::getDavPermissions($node),
				];
			} catch (\Exception $e) {
				$this->logger->error($e->getMessage(), ['path' => $headers['x-file-path']]);
				$writtenFiles[$headers['x-file-path']] = [
					'error' => true,
					'message' => $e->getMessage(),
				];
			}
		}

		$response->setStatus(Http::STATUS_OK);
		$response->setBody(json_encode($writtenFiles, JSON_THROW_ON_ERROR));

		return false;
	}
}
