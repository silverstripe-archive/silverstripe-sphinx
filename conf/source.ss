source BaseSrc  {
	<% if SupportedDatabase %>
	type = $Database.type
	sql_host = $Database.server
	sql_user = $Database.username
	sql_pass = $Database.password
	sql_db = $Database.database
	sql_port = $Database.port
	<% end_if %>
}
