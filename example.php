<?php
/*
   This is an example server using Xenophobia.
   It may be used and modified without restriction.
*/

require_once "lib.xmlrpc.php";
XMLRPCServer::handleErrors();
$server = new XMLRPCServer();
$server->serve();
