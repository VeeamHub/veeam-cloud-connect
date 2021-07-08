<?php
/**
 * FILE
 */
error_reporting(E_ALL || E_STRICT);

require 'vendor/autoload.php';
?>
<html>
  <head>
    <title>Veeam RESTful demo</title>
    <link rel="stylesheet" type="text/css" href="vendor/twbs/bootstrap/dist/css/bootstrap.min.css" />
    <script type="text/javascript" src="vendor/components/jquery/jquery.min.js"></script>
    <script type="text/javascript" src="vendor/twbs/bootstrap/dist/js/bootstrap.min.js"></script>
    <script type="text/javascript">
    $(document).ready(function() {
      $(document).ajaxStart(function(){
        $('#form_container').hide();
        $('#loading').show();
      });

      $(document).ajaxComplete(function(){
        $('#loading').hide();
      });

      $('#veeam_cloud_connect_form').submit(function() {
        $.post($(this).attr('action'), $(this).serialize(), function(json) {
          $('#result').html('<p class="bg-success"> <h2>' + json.title + '</h2>' + json.message + '</p>')
        }, 'json');
        return false;
      });
    });
    </script>
  </head>
  <body>
  <div class="container-fluid">
    <div class="row">
      <div class="col-sm-9">
        <p class="text-center"><img src="veeam_logo.png" align="center" /></p>
        <p class="text-center lead"><b>RESTful API demo for Veeam Cloud Connect</b><br />Type in your information below to instantly provision your Veeam Cloud Connect tenant</p>
        <div id="form_container">
          <form id="veeam_cloud_connect_form" method="POST" action="/veeam.php" role="form">
            <input type="hidden" name="action" value="create_tenant" />
            <div class="form-group">
              <label for="username">Username</label>
              <input type="text" name="username" class="form-control" placeholder="Enter desired username" />
            </div>
            <div class="form-group">
              <label for="email">Email</label>
              <input type="email" name="email" class="form-control" placeholder="Enter email address" />
            </div>
            <div class="form-group">
              <label for="full_name">Full name</label>
              <input type="text" name="full_name" class="form-control" placeholder="Enter full name" />
            </div>
            <div class="form-group">
              <label for="company_name">Company name</label>
              <input type="text" name="company_name" class="form-control" placeholder="Enter company name" />
            </div>
            <div class="form-group">
              <label>Resource types</label>
              <div class="checkbox">
                <label>
                  <input type="checkbox" name="create_backup" value="1" checked />
                  Cloud Connect Backup
                </label>
              </div>
              <div class="checkbox">
                <label>
                  <input type="checkbox" name="create_replication" value="1" />
                  Cloud Connect Replication
                </label>
              </div>
            </div>
            <button type="submit" class="btn btn-outline-secondary">Create account</button>
          </form>
        </div>
        <div id="loading" style="display:none;">
          <h2>Please wait...</h2>
          We are provisioning your user account. This can take a few minutes.
        </div>
        <div id="result"></div>
      </div>
    </div>
  </div>
  </body>
</html>
