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
  private $cloud_connect_site;
  private $cloud_connect_repository;
  private $backup_server_urn;
  private $backup_server_id;
  private $backup_repository_urn;
  private $backup_repository_id;
  private $hardware_plan_urn;
  private $backup_create;
  private $backup_resource;
  private $replication_create;
  private $replication_resource;
  private $company_id;
  private $tenant_name;
  private $tenant_username;
  private $tenant_description;
  private $tenant_password; // Will be randomized in __construct();

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

    // Generating random password for new tenant account
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
   * @param $hardware_plan_name
   *
   * @return string
   */
  private function veeam_get_hardware_plan($hardware_plan_name)
  {
    $response = $this->client->get('cloud/hardwarePlans');
  }

  /**
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
        // duplicates found
        return FALSE;
      }
    } else {
      throw new Exception("VSPC API call (GET - organizations/companies) was unsuccessful with response code (" . $response->getStatusCode() . ")");
    }
  }

  /**
   * @param string $action_id
   *
   * @return bool
   */
  private function veeam_follow_async_action($action_id)
  {
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
            throw new Exception("VSPC API call to create Company Site Resource was cancelled. VSPC Company will most likely need to be deleted to cleanup this failed workflow.");
          case "failed":
            throw new Exception("VSPC API call to create Company Site Resource failed with the following error message: " . $body['errors'][0]['message']);
        }
      } else {
        throw new Exception("VSPC API call (GET - asyncActions/" . $action_id . ") was unsuccessful with response code (" . $response->getStatusCode() . ")");
      }
    }
  }

  /**
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
   * @param string $company
   * @param string $site
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
  private function veeam_create_site_resource($company, $site, $type,  $vcd_organization, $expiration_enabled, $expiration_date, $username, $password, $description, $throttling_enabled, $throttling_value, $throttling_unit, $max_task, $insider_protection_enabled, $insider_protection_days, $gateway_selection_type, $gateway_pool_uids, $gateway_failover_enabled)
  {
    // Creating VSPC Site Resource (Cloud Connect Tenant)
    $params = [
      'json' => [
        "siteUid" => $site,
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
    $response = $this->client->post('organizations/companies/' . $company . '/sites', $params);

    switch (TRUE) {
      case ($response->getStatusCode() === 200):
        $body = json_decode($response->getBody(), true);
        return $body['data']['siteUid'];
      case ($response->getStatusCode() === 202):
        $location = ($response->getHeader('Location'))[0]; //this is a URL containing the async action ID
        // Using regex to extract async action ID
        preg_match('/([^\/]+$)/', $location, $matches);

        return $this->veeam_follow_async_action($matches[0]); //follows async action to completion
      default:
        throw new Exception("VSPC API call (POST - organizations/companies/" . $company . "/sites) was unsuccessful with response code (" . $response->getStatusCode() . ")");
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
    // checks for already existing customers with identical information
    if ($this->veeam_check_for_duplicates($company_name, $email)) {
      $config = include('config.php');

      // Retrieving UIDs configured values
      $this->cloud_connect_site = $this->veeam_get_cloud_connect_site($config['vcc_server']);
      $this->cloud_connect_repository = $this->veeam_get_cloud_connect_repository($this->cloud_connect_site, $config['vcc_repository']);

      // Setting description
      $description = $full_name . " - " . $company_name;

      // Setting account expiration time
      $lease_expiration = date('c', strtotime($config['tenant_account_expiration_date']));

      // Creating VSPC Company
      $this->company = $this->veeam_create_company($company_name, null, null, $email, null, null, null, null, null, $description, null, null, null, null, FALSE);

      // Creating VSPC Site Resource (Cloud Connect Tenant)
      $this->cloud_connect_site_resource = $this->veeam_create_site_resource($this->company, $this->cloud_connect_site, "General",  null, $config['tenant_account_expiration'], $lease_expiration, $username, $this->tenant_password, $description, FALSE, 1, "MbytePerSec", 1, FALSE, 7, "StandaloneGateways", null, FALSE);

      // // Creating Backup Resource if specified
      // if ($this->backup_create) {
      //   echo "Creating Backup Resource...\r\n";
      // }

      // // Creating Replication Resource if specified
      // if ($this->replication_create) {
      //   echo "Creating Replication Resource...\r\n";
      // }

      // Creating output for web front-end
      $quota = $config['backup_resource_quota'] / 1024; //converting MB to GB
      $result = array('username' => $username, 'password' => $this->tenant_password, 'quota' => $quota . 'GB');
    } else { // duplicate customer found
      // Creating output for web front-end
      $result = array('error' => 'Unable to provision account.');
    }

    // Outputting result to web page
    echo json_encode($result);
  }
}
