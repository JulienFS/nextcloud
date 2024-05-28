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
is the Node tree stuff free from race condition ? Where is the lock ? What if two requests come at the same time ?
=> Locking is implemented in custom file handling of NC, see the point on CachingTree
Leaving create and update to the node object, now lets find which implem of it
updateFile is Node put, maybe from lib/DAV/FS/File
simple file_put_contents
createFile is on dav/lib/DAV/FS/Directory
simple file_put_contents
Note : events afterCreateFile and afterWriteContent are good candidates for hash collection

WEIRD : https://github.com/sabre-io/dav/blob/7a736b73bea7040a7b6535fd92ff6de9981e5190/lib/DAV/FS/File.php#L25C9-L25C26 file_put_contents on the existing file without return check, from a stream. What it something goes wrong with the stream ?

TODO: have a look at the ChunkingV2Plugin : maybe some chunk went missing in my photos...
At first sight : why, oh why didn't you simply use HTTP ranges instead of this dirty hack...

TODO: how does NC knows about things created by Sabre ? Does it know ? Is there any "background index" or do we have to use a "web view" to trigger some mechanisms ?

It looks like there's a bunch of "reimplemented" Sabre classes in apps/dav/lib/Connector/Sabre : how to how which of them are in use VS original Sabre ?
Sabre doesn't appear in composer requirements (only dev requirements), yet its namespace is being used. Where does it come from ?
=> OK so I totally missed the fact that the CachingTree passed to the Sabre server constructor (in apps/dav/lib/Server) is in fact the NC implementation of the storage : it is at this point that NC's File handling is passed to Sabre

Bulk upload app/dav/lib/BulkUpload/BulkUploadPlugin : folder instance comes from constructor
the plugin is instanciated in apps/dav/lib/Server, the folder comes from \OC::$server->getUserFolder()
I don't understand the \OC::$server syntax, but it looks like the method called is on lib/private/Server (EDIT: see below, OC is a class and a namespace...)
Server extends ServerContainer which has a query method
ServerContainer extends SimpleContainer->get => this->query
query does some magic stuff to find instances, we probably get a LazyRoot from lib/private/Files/Node, which wraps Root
Root uses Folder from lib/private/Files/Node/Folder, which is not the DAV folder...
Folder uses View's file_put_contents from lib/private/Files/View
=> Bulk upload is using non-dav file handling, without hash mechanism

For chunks : uploaded with an upload id/chunk number
HTTP_OC_CHUNKED / $this->request->getHeader('oc-chunked') involved
OC_FileChunking::decodeName

Check external storage (OC\Files\Storage\Common)

Namespaces :
- OC : OwnCloud ?
    - /Core : files in core/ (core things :shrug:)
    - /* : files in lib/private (libs, utilities, core things in fact :shrug:)
- OCP : OwnCloud Public ?
- OCA : OwnCloud App ?
- Test : tests

dependencies :
- Sabre : DAV (file/calendar sync)
- Icewind : SMB (file server)
- ... (See https://github.com/nextcloud/3rdparty)


Paths :
- 3rdparty/ : PHP dependencies as a git submodule (https://github.com/nextcloud/3rdparty)
- apps/ : NextCloud apps, note that some "core" features are implemented as apps (why some are core, some are apps, some are libs ?)
- build/ : build stuff as in the name
- config/ : some config samples
- contribute/ : licence related instructions
- core/ : core files, however I still need to figure out what is core and what is not (as I'm not even sure a build without any app is possible)
- cypress/ : test suite
- dist/ : most likely compiled frontend resources
- lib/ : internal libraries, mostly
    - composer/ : composer setup for the lib directory
    - l10n/ : translations, but why in lib and not in some resource directory ? (or root dir ?)
    - private/ : internal stuff, like a lot of thing
    - public/ : interfaces, abstract stuff, used by private stuff, but what's the purpose ? probably hidding some lib-internal things to the rest of the system
- LICENSES/ : license files
- ocs/ : standors for Open Collaboration Service (https://www.open-collaboration-services.org/)
- ocs-provider/ : ocs-provider entrypoint, don't know what it's used for
- resources/ : resources for a framework ?
- tests/ : well, tests
- themes/ : UI themes to pimp NC
- vendor-bin/ : probably composer related

Notes :
- The OCS things seem to be moving toward "App Framework". OCS seems to be legacy ?
- \OC is a namespace, but also a class from lib/base.php. Thus \OC::$server is a static variable from the OC class. (WTF PHP ?!)
- \OC::$server seems to have a class-based key-object store, many parts of the code magically request an object of some type from it
- OCM is Open Cloud Mesh (https://github.com/cs3org/OCM-API/)
- routes are translated from name to controller : foo#bar to reference FooController::bar

Root libs (from lib/) are using apps code. While the opposite seems fine, such coupling is not ideal.
It's clearly blurrying the lines of what's core and what's not.
Same thing for \OC\* and \OC\Core : core should use libs to help, but ideally helpers should'nt use core internals.
Not that this is not an absolute rule. Having to wrap every core class with some public interface that matches exactly it's signature would'nt be helpful.
The distrinction between Core and root libs should be made clearer. Why is Core treated as a specific matter if it's only a part of the root lib ?

In an ideal scenario, Core things should provide some inter-app communication plus backbone infrastructure.
Then everything could be an app. But it looks like some efforts were made to try to appify some things while the vast
majority of thing are in root lib.

Autoloading is registered everywhere yet there's still a sort of single place registering listing in lib/private/Server.php. Maybe components should self register ?
Using https://github.com/silexphp/Pimple to register services to the server.
Why is Server called server as it's more like a utility holder than an actual server ?

There are HTTP related things happening everywhere, but it's unclear if the final answer state is always going to be fine.
Probably that some internal objects should be used all along the path, built from the requests, then a final object should buble up and be converted to HTTP
answer at last. Obviously http objects should still be there in case someone needs to peek some data.

The separation of concerns is not that clear. There are indeed services but they seem to all use each other.

There's a lot of similar concepts with the same name (File, Node...). It blurs the lines (even if namespace differs, which makes it not that dramatic)

There's only few class internal (private) helper methods. That would help to have a readable main code flow instead of overly verbose array manipulations.

To some extent, the root lib/ is too flat. The structure of the repo doesn't reflect the structure of the execution.
The fact that it's a flat list of features somehow hints toward a graph-like dependency structure : if it was a tree, it would probably appear as a dir tree.
As an example : there a probably services that operated with a nice separation of concerns. They should be in a Services dir/namespace instead of being mixes with everything.

In general, there's a global and common problem when it comes to ordering files : there are both grouped by what they are and what they are used for. There's no clear distinction of
engine related things and engine usage. As an example : the AppFramework provides a middleware mechanism, which is cool, but inside the AppFramework dir, you can find some actual middleware
implementations that provide features. The AppFramework should be core/framework/engine and the implementation/features should probably be in apps.
