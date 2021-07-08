<?php
return [
    # Veeam Service Provider Console RESTful API connection
    'vspc_rest_url' => 'https://spc.arsano.ninja/api/v3/', #default port is 1280
    'vspc_rest_user' => 'code',
    'vspc_rest_pass' => '@JAw|~Sm5V6.HySrVfS9bKk)za8zNx',
    'vspc_rest_timeout' => 10.0, #in seconds
    'vspc_tls_validation' => FALSE, #this should be TRUE in production environments
    # VCC values
    'vcc_server' => 'vcc.arsano.lab',
    'vcc_repository' => 'Default Backup Repository',
    # VCC default tenant values
    'tenant_account_expiration' => TRUE,
    'tenant_account_expiration_date' => '+3 months', #see http://php.net/manual/en/function.strtotime.php
    'tenant_enabled' => TRUE, #After creating tenant, should the tenant be left...enabled (TRUE)/disabled (FALSE)
    'tenant_password_length' => 12,
    # VCC-B values
    'backup_resource_quota' => 10737418240, #in bytes (10737418240 == 10GB)
    # VCC-R values
    'hardware_plan' => 'Hardware plan 1',
    # Response messages
    'message_success_enabled' => array(
        #provisioning success w/account enabled
        'title' => 'Account provisioned successfully!',
        'message' => "You'll receive an email shortly with connection information."
    ),
    'message_success_disabled' => array(
        #provisioning success w/account enabled
        'title' => 'Account provisioned successfully!',
        'message' => "You'll receive an email within the next 48 hours with connection information."
    ),
    'message_failure' => array(
        'title' => 'Account provisioning failure...',
        'message' => "Please try again later."
    ),
    ### Default form values
    # This is for testing purposes only. These should never be used otherwise. If so, sanitize your inputs better.
    'default_username' => 'default-tenant-name',
    'default_email' => 'chris@arsano.ninja',
    'default_full_name' => 'Veeam RESTful API demo',
    'default_company_name' => 'Default Company Name',
];
