<?php

/**
 * FILE
 */
error_reporting(E_ALL || E_STRICT);

require 'vendor/autoload.php';
require 'veeam.class.php';

header('Content-Type: application/json');

if (isset($_POST['action']) && (isset($_POST['create_backup']) || isset($_POST['create_replication'])) && $_POST['action'] == "create_tenant") {

  $config = include('config/config.php');

  $rest_url   = $config['vspc_rest_url'];
  $rest_user  = $config['vspc_rest_user'];
  $rest_pass  = $config['vspc_rest_pass'];

  // Populating form values into variables
  $username = strtolower($_POST['username']);
  $email = strtolower($_POST['email']);
  $full_name = $_POST['full_name'];
  $company_name = $_POST['company_name'];

  // DEBUG: Populating empty form variables with default values
  switch (TRUE) {
    case (empty($username)):
      $username = $config['default_username'];
    case (empty($email)):
      $email = $config['default_email'];
    case (empty($full_name)):
      $full_name = $config['default_full_name'];
    case (empty($company_name)):
      $company_name = $config['default_company_name'];
  }

  // Creating construct
  $veeam = new Veeam($rest_url, $rest_user, $rest_pass, $_POST['create_backup'], $_POST['create_replication']);

  // Create a user with the specified backup resource quota & leave the account enabled/disabled.
  $veeam->run($username, $email, $full_name, $company_name);
}
