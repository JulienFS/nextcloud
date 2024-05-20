# Architecture

The directory seems to be served as-is.
We have "front-end-able" apps in /apps.

It looks like device clients are using the remote.php endpoint.

DAV related stuff seems to be brought by the Sabre library.

PUT /remote.php/dav/files/User/Directory/file.jpeg
gets translated in remote.php by resolveService to apps/dav/appinfo/v2/remote.php
instanciate a request with \OC::$server->getRequest(); and a server from apps/dav/lib/Server.php, execute the request with the server exec method
The exec method calls exec on the internal server object, whichi is an \OCA\DAV\Connector\Sabre\Server located in apps/dav/lib//Connector/Sabre/Server.php
Which is a \Sabre\DAV\Server server extension
=> heading to Sabre dav repo
Sabre Server exec is an alias for start method
execute invokeMethod
emits 4 events: beforeMethod, methodn, afterMethod and afterResponse
=> everything goes through the "plugin" based event system. Looking for "method:PUT"...
heading to dav/lib/DAV/CorePlugin.php : httpPut method here
Fun : bug 0 byte length file with chunked encoding is described in comment
file content dealt as stream, using temp stream
investigating getNodeForPath, as Sabre deals with "nodes" (instead of files ?) => file/dir wrapper
if we fail something in the put process : let the status code to null, and let the server raise an exception upon that (or another plugin to step in ?)

Notes on the Sabre event system : there is a priority mechanism, so we could hook at the appropriate level, lets say for example on the afterResponse (https://sabre.io/event/emitter/)
Note : check that we can see if any other app registered with a higher priority so we can be sure we are not short-circuited

Notes on the bulk upload :
- it's not reusing core Sabre PUT mechanism, it's instead reimplementing it.
- it's not using a lib to parse the multipart but implementing a parser
=> might be good enough

How could this lead to some zeroed file ? Investigating stream stuff
getBodyAsStream used in httpPut : request is from Sabre\HTTP
=> heading to sabre http repo
http/lib/Request (implementing RequestInterface) gets its body stream by parameter : no dark magic on stream, it's default stream or string to stream => where does the body comes from ?
=> heading back to sabre dav repo
httpRequest is instantiated in dav/lib/DAV/Server:__construct by HTTP\Sapi getRequest
=> heading back to sabre http repo
body is a stream from php://input : what is the behavior if transfer is interrupted ?

In sabre dav repo, digging updateFile and createFile from httpPut
is the Node tree stuff free from race condition ? Where is the lock ? What if two requests come at the same time ? TODO
Leaving create and update to the node object, now lets find which implem of it
updateFile is Node put, maybe from lib/DAV/FS/File
simple file_put_contents
createFile is on dav/lib/DAV/FS/Directory
simple file_put_contents
Note : events afterCreateFile and afterWriteContent are good candidates for hash collection

WEIRD : https://github.com/sabre-io/dav/blob/7a736b73bea7040a7b6535fd92ff6de9981e5190/lib/DAV/FS/File.php#L25C9-L25C26 file_put_contents on the existing file without return check, from a stream. What it something goes wrong with the stream ?

TODO: have a look at the ChunkingV2Plugin : maybe some chunk went missing in my photos...
At first sight : why, oh why didn't you simply use HTTP ranges instead of this dirty hack...
