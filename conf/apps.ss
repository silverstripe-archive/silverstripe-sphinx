indexer {
	mem_limit = 256M
}

searchd {
	listen = $Listen
	
	pid_file = $PIDFile
	log = $VARPath/searchd.log
	query_log = $VARPath/query.log

	max_children = 30
}
