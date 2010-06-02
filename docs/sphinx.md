# Server Installation

## Install Sphinx Binaries

You will need to install sphinx 0.99 or higher on your environment. Sphinx is not distributed with the SilverStripe
sphinx module.

The sphinx binaries should be compiled with 64-bit IDs enabled.
If the xmlpipes mode is going to be used, the requisite xml libraries are also required.

Ensure the sphinxd search daemon process is set up to start when the computer reboots, running as the same user as apache
(e.g. www-data)

## Install and Configure the SilverStripe Sphinx Module

To install the module source, extract the module into the root directory of your project (or add to the svn externals
of the project). If you want near-realtime delta-indexing outside the user transaction (recommended), also install the
`messagequeue` module. Sphinx will automatically detect and use it by default.

To configure, you will need to apply the SphinxSearchable decorator to the classes that you want Sphinx to index,
and set any additional options on those classes. See the section "Applying the Decorator" for more information on ways
to configure indexing.

## Refresh Configuration and Reindex

/dev/build the project. This will update the database structure, but also generate the sphinx configuration file from
the decorated classes. This needs to be done any time there is a change to which classes are decorated, or if the class
hierarchy is changed in way that affects what is indexed, if other changes are made to indexed classes, or if the sphinx
configuration static properties are changed.

You can use the command:
`sapphire/sake dev/build` to do this.

Ensure the sphinx directory and it's contents are owned by the same user as the sphinx process.

The command:
`sapphire/sake Sphinx/reindex` can be used to force Sphinx to refresh its indexes. Note that the sphinx daemon may take
a little time to rotate the set of indexes it uses. This happens automatically.

## Set Up Periodic Reindexing

Set up a cron job to run as the apache user and issue the `sapphire/sake Sphinx/reindex` command. The effect of this
is to cause Sphinx to completely rebuild it's primary indexes, and clear the delta indexes. If this is not set up,
the delta indexes will tend to increase in size as indexed content is changed, and will increasingly degrade
system performance. Depending on the nature and size of the content, the cron job is typically set up to run periodically
anywhere from 15 minutes to a 24 hour period.

# Applying the Decorator

The following example shows how the Sphinx decorator is applied to cause indexing of a class.

`class MyPage extends Page {
	static $db = array(
		...
	);

	static $extensions = array(
		'SphinxSearchable'
	);

	static $sphinx = array(
		"search_fields" => array("a", "b", "c"),
		"filter_fields" => array("a", "c"),
		"index_filter" => '"ShowInSearch" = 1',
		"sort_fields" => array("Title"),
		"extra_fields" => array("_contenttype" => "Page::getComputedValue"),
		'filterable_many_many' => '*',
		'extra_many_many' => array('documents' => 'select (1468840814<<32) | PageID AS id, DocumentID AS Documents FROM Page_Documents')
		"mode" => "xmlpipe",
		"external_content" => array("field" => array("myclass", "somefunc"))
	);
`

This will mark the class and all sub-classes for indexing.

## Properties of the $sphinx Static

* search_fields - an array of fields in this class to be indexed. If $excludeByDefault (see below) is false and searchFields is not supplied, all
  fields are index. If $excludeByDefault is true, this must be supplied.
* filter_fields - an array of fields in this class that can be filtered. If $excludeByDefault is false and filterFields is not supplied, all
  non-string fields will be made filterable (including from has_one relationships). If $excludeByDefault is true, this must be supplied to
  have fields made filterable.
* index_filter - A SQL where clause to filter the index by; it will be impossible to search for anything that does not meet this criteria.
* sort_fields - an array of fields that can be sorted on. Similar to filterFields in that all fields listed here are added as filters, but this
  can include string fields. You can only sort on fields that:
  * special attributes created by sphinx
  * non-string fields that are in filterFields (or any non-int if $excludeByDefault is false)
  * string fields that are explicitly defined in sortFields. Because Sphinx cannot filter on string fields, special behaviour is
    implemented to create proxy int filter fields, which are then sorted more accurately once the result is returned from the sphinx process.
* extra_fields - defines extra fields into the main SQL used for generating indexes, and includes them as attributes in the index. The value can
  be a SQL expression, but can also be of the form "class::method" which is called using call_user_func to get the value. The resulting value should
  be a SQL expression that returns an int.
* filterable_many_many - an array of many-many relationship names, or '*' for all many-many relationships on this class. These are added as
  filters to the index. 
* extra_many_many - this allows injection of many-many attributes bypassing sapphire's generation of SQL automatically of the relationship. This
  is specifically useful for working around an issue with sapphire 2.4, which generates ANSI compliant SQL statements, but these fail in
  sphinx indexing if the database server is not set to use ANSI compliant, because there is no way to control ansi-mode for the query
  that retrieves many-many data in sphinx (a bug in sphinx: http://www.sphinxsearch.com/bugs/view.php?id=394). This is not an issue
  if mode is 'xmlpipe'.
* mode - this determines the mode used by the sphinx indexer to retrieve data. One of the values:
  * 'sql' (default) - SQL statements are used. The statements are written to the sphinx.conf file. Indexer handles database connections itself.
  * 'xmlpipe' - the indexer runs a command that invokes the SphinxXMLPipe controller to get the data to index as an XML stream. Experimental
    at this stage (well, more experimental than SQL). This is likely to be slower than SQL indexing. Has the advantage that content outside
    the database can be included in the index.
* external_content provides a hook to provide additional content to add to the search index. The value provided is passed as the function
  argument to call_user_func, so can be a function, array($instance, $functionName) for an instance method, or
  array($className, $functionName) for a static function. NOTE: This is only applicable when mode is 'xmlpipe'. It is ignored if
  mode is 'sql'. The function is called with a single parameter, the ID of the decorated instance.

## Parameters of the Constructor

* excludeByDefault (default false) When false, all properties on sub-classes are automatically indexed, and all non-string fields are
  made filterable. This gives maximum searchability with the cost of potentially increasing the number of indices, and increasing the
  memory footprint of searchd. If this is set to true, fields in subclasses are excluded from indexing unless the sub-class specifically
  defines $searchFields, $filterFields.
  e.g. `static $extensions = array('SphinxSearchable(true)'); // disable automatic inclusion of all subclass fields`

## Notes on How Indexes are Constructed

The Sphinx module automatically determines the indexes required for a given set of classes. It calculates a signature
for each searchable class that incorporates the fields to be indexed, extra fields and attributes for filtering.
Classes with the same signature are combined into a single index.

For example, consider a class A that is decorated with SphinxSearchable, with subclasses B and C. B does not have any
additional indexable fields, but C does. Objects of class B will be put in the same index as objects of class A, but
a separate index will be constructed for class C.

## Triggers for Reindexing

Sphinx maintains two sets of indexes, primary and deltas. A primary delta will contain all indexed objects (for the
classes for that index), whereas the delta index only contains recently changed objects. Each time an indexed object
is changed, the SphinxSearchable decorator invalidates that object in the primary index, and re-indexes the delta
index to pick up just those that have changed since the primary was last rebuilt.

As changes occur, the delta index will grow, and will progressively get slower to index, until the primary index is
rebuilt and the delta index cleared.


The primary index is only rebuilt as a result of calling Sphinx::reindex()  (Sphinx class is a controller, so accessing
Sphinx/reindex will do this). This is typically set up as a cron job.

The reindexing of deltas is controlled by the static variable `SphinxSearchable::$reindex_mode`:
* If "endrequest" (the default) reindexing is done once at the end of the PHP request, and only if a write() or delete()
  have been done (any op which flags the record dirty). If the messagequeue module is installed and
  `SphinxSearchable::$reindex_queue` is specified, a message is sent to do the refresh to keep it out of the user process.
  Otherwise it done in this process but at the end of the PHP request (this will be noticable to the user)
* If "write" (old behaviour) reindexing is done on write or delete.
* If "disabled" reindexing of the delta is disabled, which is useful when writing many SphinxSearchable items
  (such as during a migration import) where the burden of keeping the Sphinx index updated in realtime is both
  unneccesary and prohibitive.

Note that the default configuration of messagequeue will execute the delta reindexing in a separate process initiated
as part of PHP shutdown. The effect is the delta is reindexed in near-realtime, but without the user experiencing
the delay. If xmlpipes is used, messagequeue is highly recommended.

# Indexing Content of Files

Sphinx module can be configured index file contents. This optional feature relies on extractor classes that use external tools to 
get the text for a file. This module currently provides two file extractor classes:

* PDFTextExtractor - uses pdftotext utility
* HTML extractor - uses internal striptags method to crudely get content

Other extractors can be added by defining subclasses of FileTextExtractor.

## Configuration

Add the extension in your mysite/_config.php:

`
	DataObject::add_extension('File', 'FileTextExtractable');
`

## Indexing File via another Class (e.g. a Document class)

On your document class (which is assumed to contain a has_one relationship to File), configure sphinx to index it. The key properties
are setting the mode to "xmlpipe" and external_content to point to a function in the class that retrieves the content. In this case,
the function simply calls extractFileAsText() (in the decorator) on the related file object.
`
	static $sphinx = array(
		"search_fields" => array("Title","Description"),
		"mode" => "xmlpipe",
		"external_content" => array("file_content" => array("Document", "getDocumentContent"))
		);

	static function getDocumentContent($documentId) {
		$doc = DataObject::get_by_id("Document", $documentId);
		if (!$doc || !$doc->File()) return "";
		return $doc->File()->extractFileAsText();
	}

`

Generally File should not be directly indexed, as this provides no control over which files are indexed.

# Doing a search

`SphinxSearch::search()` actually performs a search. e.g.:

`
		$res = SphinxSearch::search(array('Page', 'Document',
									$queryString,
									array(  'require' => $includeFilters,
											'exclude' => $excludeFilters,
											'page' => $page,
											'pagesize' => $pagesize,
											'sortmode' => $sortmode,
											'sortarg' => $sortarg,
											'suggestions' => true)));
`

The parameters are:

* An array of classes to search. Subclasses are also searched. The set of indexed is automatically determined.
* The query string itself, which is just a string with space-separated words and other tokens to be interpreted by
  sphinx. See the sphinx documentation for the available options.
* An options array

Available options are:
* 'require' - an array of inclusion filters that are passed to Sphinx. Results will match these filters.
* 'exclude' - an array of exclusion filters that are passed to Sphinx. Results will not match these filters.
* 'page' - in a multi-page result, this is the page of results
* 'pagesize' - the number of results to return in each page.
* 'sortmode' - the sorting mode to user.
* 'sortarg' - an argument to the sorting mode.
* 'suggestions' - if true, alternative spelling suggestions are returned if there are less than 10 results. If false,
  suggestions are never returned.

The result is an associative array with the following keys:
* 'Matches' - a DataObjectSet with the search results.
* 'Suggestions' - if suggestions are enabled and there are less than 10, this will be an array of possible values.

  
# Managing Larger Configurations

Sphinx performance and resource usage is affected by a number of factors, including:

* Number of indices
* Whether or not deltas are used
* The number of attributes in the indices. These are kept in searchd memory.

Ways to control these factors include:

* Attach the decorator at a deeper level in the class tree. e.g. instead of decorating Page, decorate specific subclasses of Page.
* Use the excludeByDefault option on the constructor, and explicitly control the search, filter and order fields on the class.
* For classes that change very infrequently, or are small, consider disabling delta indices.
* For versioned pages, if search is not required in the CMS, consider explicit control over the stages that are indexed. (e.g. only index Live
  if searching is only enabled  at the front end.)

# Best Practices

* On larger sites, don't make SiteTree or Page searchable directly, but create sub-classes of Page and decorate those.
  This provides better control over what classes are indexed (e.g. a Page derivative whose content is to summarise
  other content or pages will probably not want to be indexed).
* If files need to be indexed, consider sub-classing File rather than decorating it directly, as this will cause overhead
  in attempting to index non-indexable files such as images, or use the index_filter property to be selective on which
  files are indexed.

# Known Issues

## Re-indexing Many-Many Relationships on Write

Currently many-many relationships are not re-indexed on write, as there is no way to reliably detect changes in the components if the decorated
object doesn't change. So if changes are made in a M-M, these need to be re-indexed by calling $do->sphinxComponentsChanged() on the decorated
instance. This will re-index the object in the delta. Otherwise the M-M changes will be picked up at the next primary re-index.

## Slow Saving with XML Pipes

Saving pages that are indexed using XML pipes can be very slow in the CMS. This is due to the relatively high overhead
of invoking the framework from the command line interactively in order to re-index the delta. This is compounded by
versioning (which doubles the number of writes), cmsworkflow and any other decorator that introduces additional write()
calls to the data object.

# Troubleshooting

The first thing to do is issue the command:
`sapphire/sake Sphinx/diagnose` on the command line. This will attempt to find a number of common conditions such as:

* no classes are decorated with SphinxSearchable
* the sphinx binaries aren't installed
* the configuration file or indexes haven't been built
* indexes are not in the sphinx configuration file that should be, indicating changes to the decorated classes
  without a dev/build.
* delta indexes are populated but primaries not, indicating a reindex has never been done.

More tests will be added over time.

# Is the Sphinx configuration file being Created?

* Check permissions

# Can the Indexer Build all the Indices?

* Error is 'No local indices'
* Check that the class being searched actually have indices
* Check the sphinx.conf to ensure config for that class is correct.
* Use 'indexer' on the config file to see if there are errors.

## Errors

### "failed to send client protocol version"

This error has been seen intermittently. It has been worked around with a change directly in thirdparty/sphinxapi.php.

## Sorting Issues

* If sorting on a text field, it must be declared as a sortable column

# Further Enhancements

## Performance of XML Pipes Write

XML pipes performance is not as high as with SQL, and carries additional overhead of invoking a sake instance
to run the controller. If the messagequeue module is installed, sphinx will automatically use it and send reindex
requests to a queue named by SphinxSearchable::$reindex_queue (default being sphinx_indexing). If the
message queue interface that handles this queue is set to "send" => "processOnShutdown" => true (the default
interface), then the reindexing requests will be performed on PHP shutdown in a separate process. In this configuration,
reindexing of deltas is almost interactive without the user having the penalty. The queueing system can be used
in other configurations, for example to offload indexing to another server that shares a database or message queue.
