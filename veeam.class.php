<?php

/**
 * FILE
 */
error_reporting(E_ALL || E_STRICT);

require 'vendor/autoload.php';

/**
 * Class Veeam
 */
class Veeam
{
  private $client;
  private $access_token;
  private $cloud_connect_site_id;
  private $cloud_connect_repository_id;
  private $cloud_connect_hardware_plan_id;
  private $backup_create;
  private $replication_create;
  private $company_id;
  private $tenant_description;
  private $tenant_password; // Will be randomized in __construct();
  private $tenant_lease_expiration;

  /**
   * @param str $base_url
   * @param str $username
   * @param str $password
   * @param bool $backup
   * @param bool $replication
   */
  public function __construct($base_url, $username, $password, $backup, $replication)
  {
    $config = include('config.php');

    // Initializing API client
    $this->client = new GuzzleHttp\Client([
      'base_uri' => $base_url,
      'timeout'  => $config['vspc_rest_timeout'],
      'verify' => $config['vspc_tls_validation'],
    ]);

    try {
      // Authenticating to VSPC API
      $response = $this->client->request('POST', 'token', [
        'form_params' => [
          'grant_type' => 'password',
          'username' => $username,
          'password' => $password
        ]
      ]);

      if ($response->getStatusCode() === 200) {
        $result =  json_decode($response->getBody(), true);
        $this->access_token = (string) $result['access_token'];

        // Creating new client with access token specified
        $this->client = new GuzzleHttp\Client([
          'base_uri' => $base_url,
          'timeout'  => $config['vspc_rest_timeout'],
          'verify' => $config['vspc_tls_validation'],
          'headers' => [
            'Authorization' => 'Bearer ' . $result['access_token']
          ]
        ]);
      } else {
        throw new Exception("Unable to login to VSPC API. Please verify your connection information located in config.php is valid.");
      }
    } catch (GuzzleHttp\Exception\TransferException $e) {
      throw new Exception("Unable to validate VSPC certificate as valid/trusted. Please make sure you are using a trusted certificate with matching connection information. If this is a lab/test environment, you can set 'vspc_tls_validation' in your config.php to FALSE.");
    }

    // Setting form selections
    $this->backup_create = $backup;
    $this->replication_create = $replication;

    // Generating random password for new account
    $this->tenant_password = $this->veeam_generate_password($config['tenant_password_length']);
  }

  /**
   *
   */
  public function __destruct()
  {
    $this->veeam_delete_session();
  }

  /**
   * @param int $length
   *
   * @return string
   */
  private function veeam_generate_password($length = 12)
  {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    return substr(str_shuffle($chars), 0, $length);
  }

  /**
   * https://helpcenter.veeam.com/docs/vac/rest/reference/vspc-rest.html#operation/GetSites
   * @param str $name
   *
   * @return string
   */
  private function veeam_get_cloud_connect_site($name = FALSE)
  {
    if ($name <> FALSE) {
      // Retrieving Cloud Connect Site matching the name specified
      $params = [
        'query' => 'filter=[{
          "property": "siteName",
          "operation": "equals",
          "value": "' . $name . '"
        }]'
      ];
      $response = $this->client->get('infrastructure/sites', $params);

      if ($response->getStatusCode() === 200) {
        $body = json_decode($response->getBody(), true);

        // In case no matches are returned
        if ($body['meta']['pagingInfo']['total'] === 0) {
          throw new Exception("No Cloud Connect Site found. Please verify 'vcc_server' located in config.php matches what's been defined in the VSPC web UI.");
        }

        return $body['data'][0]['siteUid'];
      } else {
        throw new Exception("VSPC API call (GET - infrastructure/sites) was unsuccessful with response code (" . $response->getStatusCode() . ")");
      }
    } else {
      throw new Exception("'vcc_server' must be specified in config.php. Please define this value to avoid this error.");
    }
  }

  /**
   * https://helpcenter.veeam.com/docs/vac/rest/reference/vspc-rest.html#operation/GetBackupRepositories
   * @param $backup_server_id
   * @param $backup_repository_name
   *
   * @return string
   */
  private function veeam_get_cloud_connect_repository($site_id, $name = FALSE)
  {
    if ($name <> FALSE) {
      // Retrieving Cloud Connect Site matching the name specified
      $params = [
        'query' => 'filter=[{
          "operation": "and",
          "items": [
            {
              "property": "backupServerUid",
              "operation": "equals",
              "value": "' . $site_id . '"
            },{
              "property": "name",
              "operation": "equals",
              "value": "' . $name . '"
            },{
              "property": "isCloud",
              "operation": "equals",
              "value": true
            }
          ]
        }]'
      ];
      $response = $this->client->get('infrastructure/backupServers/repositories', $params);

      if ($response->getStatusCode() === 200) {
        $body = json_decode($response->getBody(), true);

        // In case no matches are returned
        if ($body['meta']['pagingInfo']['total'] === 0) {
          throw new Exception("No Cloud Connect Repository found. Please verify 'vcc_repository' located in config.php matches what's shown in the VSPC web UI.");
        }

        return $body['data'][0]['instanceUid'];
      } else {
        throw new Exception("VSPC API call (GET - infrastructure/backupServers/repositories) was unsuccessful with response code (" . $response->getStatusCode() . ")");
      }
    } else {
      throw new Exception("'vcc_repository' must be specified in config.php. Please define this value to avoid this error.");
    }
  }

  /**
   * https://helpcenter.veeam.com/docs/vac/rest/reference/vspc-rest.html#operation/GetBackupHardwarePlans
   * @param string $site_id
   * @param string $name
   *
   * @return string
   */
  private function veeam_get_cloud_connect_hardware_plan($site_id, $name = FALSE)
  {
    if ($name <> FALSE) {
      // Retrieving Cloud Connect Site matching the name specified
      $params = [
        'query' => 'filter=[{
          "operation": "and",
          "items": [
            {
              "property": "backupServerUid",
              "operation": "equals",
              "value": "' . $site_id . '"
            },{
              "property": "name",
              "operation": "equals",
              "value": "' . $name . '"
            }
          ]
        }]'
      ];
      $response = $this->client->get('infrastructure/sites/hardwarePlans', $params);

      if ($response->getStatusCode() === 200) {
        $body = json_decode($response->getBody(), true);

        // In case no matches are returned
        if ($body['meta']['pagingInfo']['total'] === 0) {
          throw new Exception("No Hardware Plan found. Please verify 'hardware_plan' located in config.php matches what's been defined in the VSPC web UI.");
        }

        return $body['data'][0]['instanceUid'];
      } else {
        throw new Exception("VSPC API call (GET - infrastructure/sites/hardwarePlans) was unsuccessful with response code (" . $response->getStatusCode() . ")");
      }
    } else {
      throw new Exception("'hardware_plan' must be specified in config.php. Please define this value to avoid this error.");
    }
  }

  /**
   * https://helpcenter.veeam.com/docs/vac/rest/reference/vspc-rest.html#operation/GetOrganizations
   * @param $email
   * @param $company_name
   *
   * @return bool
   */
  private function veeam_check_for_duplicates($company_name, $email)
  {
    // Checking to see if a VSPC Company already exists with one of the defined form values
    $params = [
      'query' => 'filter=[{
        "operation": "and",
        "items": [
          {
            "property": "name",
            "operation": "equals",
            "value": "' . $company_name . '"
          },{
            "property": "email",
            "operation": "equals",
            "value": "' . $email . '"
          }
        ]
      }]'
    ];
    $response = $this->client->get('organizations', $params);

    if ($response->getStatusCode() === 200) {
      $body = json_decode($response->getBody(), true);

      // In case no matches are returned
      if ($body['meta']['pagingInfo']['total'] === 0) {
        // no duplicates found
        return TRUE;
      } else {
        throw new Exception("Duplicate VSPC Company found. Terminating account creation.");
      }
    } else {
      throw new Exception("VSPC API call (GET - organizations/companies) was unsuccessful with response code (" . $response->getStatusCode() . ")");
    }
  }

  /**
   * https://helpcenter.veeam.com/docs/vac/rest/reference/vspc-rest.html#operation/GetAsyncActionInfo
   * @param string $location
   *
   * @return bool
   */
  private function veeam_follow_async_action($location)
  {
    // Using regex to extract async action ID
    preg_match('/([^\/]+$)/', $location, $matches);
    $action_id = $matches[0];

    while (TRUE) {
      $response = $this->client->get('asyncActions/' . $action_id);

      if ($response->getStatusCode() === 200) {
        $body = json_decode($response->getBody(), true);
        switch ($body['data']['status']) {
          case "running":
            sleep(10);
            break;
          case "succeed":
            return TRUE;
          case "canceled":
            throw new Exception("VSPC API call to " . $body['data']['actionName'] . " was cancelled. VSPC Company will most likely need to be deleted to cleanup this failed workflow.");
          case "failed":
            throw new Exception("VSPC API call to " . $body['data']['actionName'] . " failed with the following error message: " . $body['errors'][0]['message']);
        }
      } else {
        throw new Exception("VSPC API call (GET - asyncActions/" . $action_id . ") was unsuccessful with response code (" . $response->getStatusCode() . ")");
      }
    }
  }

  /**
   * https://helpcenter.veeam.com/docs/vac/rest/reference/vspc-rest.html#operation/GetCurrentUser
   * https://helpcenter.veeam.com/docs/vac/rest/reference/vspc-rest.html#operation/RevokeAuthenticationToken
   * @return bool
   */
  private function veeam_delete_session()
  {
    if (isset($this->access_token)) {
      // Retrieving current user
      $response = $this->client->get('users/me');
      $result =  json_decode($response->getBody(), true);
      $user_uid = (string) $result['data']['instanceUid'];

      // Revoking access token
      $response = $this->client->delete('users/' . $user_uid . '/tokens');
      return $response->getStatusCode() == 200;
    } else {
      return FALSE;
    }
  }

  /**
   * https://helpcenter.veeam.com/docs/vac/rest/reference/vspc-rest.html#operation/PatchCompany
   * @param string $company_id
   *
   * @return bool
   */
  private function veeam_disable_company($company_id)
  {
    // Creating Cloud Connect Replication Resource
    $params = [
      'json' => [
        [
          "value" => "Disabled",
          "path" => "/status",
          "op" => "replace"
        ]
      ]
    ];
    $response = $this->client->patch('organizations/companies/' . $company_id, $params);

    switch (TRUE) {
      case ($response->getStatusCode() === 200):
        return TRUE;
      case ($response->getStatusCode() === 202):
        $location = ($response->getHeader('Location'))[0]; //this is a URL containing the async action ID

        return $this->veeam_follow_async_action($location); //follows async action to completion
      default:
        throw new Exception("VSPC API call (POST - organizations/companies/" . $company_id . ") was unsuccessful with response code (" . $response->getStatusCode() . ")");
    }
  }

  /**
   * https://helpcenter.veeam.com/docs/vac/rest/reference/vspc-rest.html#operation/CreateCompany
   * @param string $company_name
   * @param string $alias
   * @param string $tax_id
   * @param string $email
   * @param string $phone
   * @param int $country
   * @param int $state
   * @param string $city
   * @param string $street
   * @param string $description
   * @param string $zip_code
   * @param string $website
   * @param string $company_id
   * @param string $subscription_plan
   * @param bool $enable_alarms
   *
   * @return string
   */
  private function veeam_create_company($company_name, $alias, $tax_id, $email, $phone, $country, $state, $city, $street, $description, $zip_code, $website, $company_id, $subscription_plan, $enable_alarms)
  {
    // Creating Company in VSPC
    $params = [
      'json' => [
        "resellerUid" => null,
        "organizationInput" => [
          "name" => $company_name,
          "alias" => $alias,
          "taxId" => $tax_id,
          "email" => $email,
          "phone" => $phone,
          "country" => $country,
          "state" => $state,
          "city" => $city,
          "street" => $street,
          "notes" => $description,
          "zipCode" => $zip_code,
          "website" => $website,
          "companyId" => $company_id
        ],
        "subscriptionPlanUid" => $subscription_plan,
        "permissions" => [],
        "IsAlarmDetectEnabled" => $enable_alarms
      ]
    ];
    $response = $this->client->post('organizations/companies', $params);

    if ($response->getStatusCode() === 200) {
      $body = json_decode($response->getBody(), true);

      return $body['data']['instanceUid'];
    } else {
      throw new Exception("VSPC API call (POST - organizations/companies) was unsuccessful with response code (" . $response->getStatusCode() . ")");
    }
  }

  /**
   * https://helpcenter.veeam.com/docs/vac/rest/reference/vspc-rest.html#operation/CreateCompanySiteResource
   * @param string $company_id
   * @param string $site_id
   * @param string $type
   * @param string $vcd_organization
   * @param bool $expiration_enabled
   * @param string $expiration_date
   * @param string $username
   * @param string $password
   * @param string $description
   * @param bool $throttling_enabled
   * @param int $throttling_value
   * @param string $throttling_unit
   * @param int $max_task
   * @param bool $insider_protection_enabled
   * @param int $insider_protection_days
   * @param string $gateway_selection_type
   * @param array $gateway_pool_uids
   * @param bool $gateway_failover_enabled
   *
   * @return bool
   */
  private function veeam_create_site_resource($company_id, $site_id, $type,  $vcd_organization, $expiration_enabled, $expiration_date, $username, $password, $description, $throttling_enabled, $throttling_value, $throttling_unit, $max_task, $insider_protection_enabled, $insider_protection_days, $gateway_selection_type, $gateway_pool_uids, $gateway_failover_enabled)
  {
    // Creating VSPC Site Resource (Cloud Connect Tenant)
    $params = [
      'json' => [
        "siteUid" => $site_id,
        "cloudTenantType" => $type,
        "vCloudOrganizationUid" => $vcd_organization,
        "leaseExpirationEnabled" => $expiration_enabled,
        "leaseExpirationDate" => $expiration_date,
        "ownerCredentials" => [
          "userName" => $username,
          "password" => $password
        ],
        "description" => $description,
        "throttlingEnabled" => $throttling_enabled,
        "throttlingValue" => $throttling_value,
        "throttlingUnit" => $throttling_unit,
        "maxConcurrentTask" => $max_task,
        "backupProtectionEnabled" => $insider_protection_enabled,
        "backupProtectionPeriodDays" => $insider_protection_days,
        "gatewaySelectionType" => $gateway_selection_type,
        "gatewayPoolsUids" => $gateway_pool_uids,
        "isGatewayFailoverEnabled" => $gateway_failover_enabled
      ]
    ];
    $response = $this->client->post('organizations/companies/' . $company_id . '/sites', $params);

    switch (TRUE) {
      case ($response->getStatusCode() === 200):
        return TRUE;
      case ($response->getStatusCode() === 202):
        $location = ($response->getHeader('Location'))[0]; //this is a URL containing the async action ID

        return $this->veeam_follow_async_action($location); //follows async action to completion
      default:
        throw new Exception("VSPC API call (POST - organizations/companies/" . $company_id . "/sites) was unsuccessful with response code (" . $response->getStatusCode() . ")");
    }
  }

  /**
   * https://helpcenter.veeam.com/docs/vac/rest/reference/vspc-rest.html#operation/CreateCompanySiteBackupResource
   * @param string $company_id
   * @param string $site_id
   * @param string $repo_id
   * @param string $repo_name
   * @param int $quota_storage
   * @param string $quota_server
   * @param bool $quota_server_unlimited
   * @param string $quota_workstation
   * @param bool $quota_workstation_unlimited
   * @param string $quota_vm
   * @param bool $quota_vm_unlimited
   * @param bool $wan_acceleration_enabled
   * @param string $wan_accelerator_id
   * @param bool $default
   *
   * @return bool
   */
  private function veeam_create_backup_resource($company_id, $site_id, $repo_id, $repo_name, $quota_storage, $quota_server, $quota_server_unlimited, $quota_workstation, $quota_workstation_unlimited, $quota_vm, $quota_vm_unlimited, $wan_acceleration_enabled, $wan_accelerator_id, $default)
  {
    // Creating Cloud Connect Backup Resource
    $params = [
      'json' => [
        "repositoryUid" => $repo_id,
        "cloudRepositoryName" => $repo_name,
        "storageQuota" => $quota_storage,
        "serversQuota" => $quota_server,
        "isServersQuotaUnlimited" => $quota_server_unlimited,
        "workstationsQuota" => $quota_workstation,
        "isWorkstationsQuotaUnlimited" => $quota_workstation_unlimited,
        "vmsQuota" => $quota_vm,
        "isVmsQuotaUnlimited" => $quota_vm_unlimited,
        "isWanAccelerationEnabled" => $wan_acceleration_enabled,
        "wanAcceleratorUid" => $wan_accelerator_id,
        "isDefault" => $default
      ]
    ];
    $response = $this->client->post('organizations/companies/' . $company_id . '/sites/' . $site_id . '/backupResources', $params);

    switch (TRUE) {
      case ($response->getStatusCode() === 200):
        return TRUE;
      case ($response->getStatusCode() === 202):
        $location = ($response->getHeader('Location'))[0]; //this is a URL containing the async action ID

        return $this->veeam_follow_async_action($location); //follows async action to completion
      default:
        throw new Exception("VSPC API call (POST - organizations/companies/" . $company_id . "/sites/" . $site_id . "/backupResources) was unsuccessful with response code (" . $response->getStatusCode() . ")");
    }
  }

  /**
   * https://helpcenter.veeam.com/docs/vac/rest/reference/vspc-rest.html#operation/CreateCompanySiteReplicationResource
   * @param string $company_id
   * @param string $site_id
   * @param string $hardware_plan_id
   * @param bool $wan_acceleration_enabled
   * @param string $wan_accelerator_id
   * @param bool $failover_enabled
   * @param bool $public_ips_enabled
   * @param int $public_ips
   *
   * @return bool
   */
  private function veeam_create_replication_resource($company_id, $site_id, $hardware_plan_id, $wan_acceleration_enabled, $wan_accelerator_id, $failover_enabled, $public_ips_enabled, $public_ips)
  {
    // Creating Cloud Connect Replication Resource
    $params = [
      'json' => [
        "hardwarePlans" => [
          [
            "hardwarePlanUid" => $hardware_plan_id,
            "isWanAccelerationEnabled" => $wan_acceleration_enabled,
            "wanAcceleratorUid" => $wan_accelerator_id
          ]
        ],
        "isFailoverCapabilitiesEnabled" => $failover_enabled,
        "isPublicAllocationEnabled" => $public_ips_enabled,
        "numberOfPublicIps" => $public_ips
      ]
    ];
    $response = $this->client->post('organizations/companies/' . $company_id . '/sites/' . $site_id . '/replicationResources', $params);

    switch (TRUE) {
      case ($response->getStatusCode() === 200):
        return TRUE;
      case ($response->getStatusCode() === 202):
        $location = ($response->getHeader('Location'))[0]; //this is a URL containing the async action ID

        return $this->veeam_follow_async_action($location); //follows async action to completion
      default:
        throw new Exception("VSPC API call (POST - organizations/companies/" . $company_id . "/sites/" . $site_id . "/replicationResources) was unsuccessful with response code (" . $response->getStatusCode() . ")");
    }
  }

  /**
   * https://helpcenter.veeam.com/docs/vac/rest/reference/vspc-rest.html#operation/SendWelcomeEmailToCompany
   * @param string $company_id
   * @param string $password
   *
   * @return bool
   */
  private function veeam_send_welcome_email($company_id, $password)
  {
    // Sending welcome email to customer
    $params = [
      'json' => [
        "password" => $password
      ]
    ];
    $response = $this->client->post('organizations/companies/' . $company_id . '/welcomeEmail', $params);

    switch (TRUE) {
      case ($response->getStatusCode() === 200):
        return TRUE;
      case ($response->getStatusCode() === 202):
        $location = ($response->getHeader('Location'))[0]; //this is a URL containing the async action ID

        return $this->veeam_follow_async_action($location); //follows async action to completion
      default:
        throw new Exception("VSPC API call (POST - organizations/companies/" . $company_id . "/welcomeEmail) was unsuccessful with response code (" . $response->getStatusCode() . ")");
    }
  }

  /**
   * @param string $username
   * @param string $email
   * @param string $full_name
   * @param string $company_name
   *
   */
  public function run($username, $email, $full_name, $company_name)
  {
    $config = include('config.php');

    try {
      // checks for already existing customers with identical information
      $this->veeam_check_for_duplicates($company_name, $email);

      // Retrieving UIDs configured values
      $this->cloud_connect_site_id = $this->veeam_get_cloud_connect_site($config['vcc_server']);
      $this->cloud_connect_repository_id = $this->veeam_get_cloud_connect_repository($this->cloud_connect_site_id, $config['vcc_repository']);

      // Setting description
      $this->tenant_description = $full_name . " - " . $company_name;

      // Setting account expiration time
      $this->tenant_lease_expiration = date('c', strtotime($config['tenant_account_expiration_date']));

      // Creating VSPC Company
      $this->company_id = $this->veeam_create_company($company_name, null, null, $email, null, null, null, null, null, $this->tenant_description, null, null, null, null, FALSE);

      // Creating VSPC Site Resource (Cloud Connect Tenant)
      $this->cloud_connect_site_resource = $this->veeam_create_site_resource($this->company_id, $this->cloud_connect_site_id, "General",  null, $config['tenant_account_expiration'], $this->tenant_lease_expiration, $username, $this->tenant_password, $this->tenant_description, FALSE, 1, "MbytePerSec", 1, FALSE, 7, "StandaloneGateways", null, FALSE);

      // Should newly created account be enabled?
      if (!$config['tenant_enabled']) {
        // Disabling newly created Company
        $this->veeam_disable_company($this->company_id);
      }

      // Create Backup Resource?
      if ($this->backup_create) {
        // Setting Cloud Repository name
        $repo_name = 'cloud-' . $company_name . '-01';

        // Creating Backup Resource
        $this->veeam_create_backup_resource($this->company_id, $this->cloud_connect_site_id, $this->cloud_connect_repository_id, $repo_name, $config['backup_resource_quota'], null, TRUE, null, TRUE, null, TRUE, FALSE, null, TRUE);
      }

      // Create Replication Resource?
      if ($this->replication_create) {
        // Retrieving Hardware Plan ID
        $this->cloud_connect_hardware_plan_id = $this->veeam_get_cloud_connect_hardware_plan($this->cloud_connect_site_id, $config['hardware_plan']);

        // Creating Replication Resource
        $this->veeam_create_replication_resource($this->company_id, $this->cloud_connect_site_id, $this->cloud_connect_hardware_plan_id, FALSE, null, FALSE, FALSE, 0);
      }

      // Is newly created account enabled?
      if ($config['tenant_enabled']) { // email sent
        // Sending welcome email to customer
        $this->veeam_send_welcome_email($this->company_id, $this->tenant_password);

        // Creating output for web front-end
        $result = $config['message_success_enabled'];
      } else { // email not sent
        // Creating output for web front-end
        $result = $config['message_success_disabled'];
      }
    } catch (Exception $e) {
      // Creating output for web front-end
      $result = $config['message_failure'];
    }

    // Outputting result to web page
    echo json_encode($result);
  }
}
