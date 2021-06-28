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
        throw new Exception("VSPC API call was unsuccessful with response code (" . $response->getStatusCode() . ")");
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
        throw new Exception("VSPC API call was unsuccessful with response code (" . $response->getStatusCode() . ")");
      }
    } else {
      throw new Exception("'vcc_repository' must be specified in config.php. Please define this value to avoid this error.");
    }
  }

  private function veeam_get_hardware_plan($hardware_plan_name)
  {
    $response = $this->client->get('cloud/hardwarePlans');

    foreach ($response->xml()->Ref as $hardware_plan) {
      if (strtolower($hardware_plan_name) == strtolower($hardware_plan['Name'])) {
        if (array_pop(explode("/", $hardware_plan->Links->Link[0]['Href'])) == $this->backup_server_id) {
          return (string) $hardware_plan['UID'];
        }
      }
    }
  }

  /**
   * @param $username
   *
   * @return bool
   */
  private function veeam_check_username($username)
  {
    $response = $this->client->get('cloud/tenants?format=Entity');

    foreach ($response->xml()->CloudTenant as $tenant) {
      if (strtolower($username) == strtolower($tenant['Name'])) {
        return true;
      }
    }

    return false;
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
  private function veeam_create_company($company_name, $alias = null, $tax_id = "", $email, $phone = "", $country = null, $state = null, $city = "", $street = "", $description, $zip_code = "", $website = "", $company_id = "", $subscription_plan = null, $enable_alarms = FALSE)
  {
    // Creating Company in VSPC
    $params = [
      'json' => [
        "resellerUid" => null,
        "organizationInput" => [
          "name" => $company_name,
          "alias" => null,
          "taxId" => "",
          "email" => $email,
          "phone" => "",
          "country" => null,
          "state" => null,
          "city" => "",
          "street" => "",
          "notes" => $description,
          "zipCode" => "",
          "website" => "",
          "companyId" => ""
        ],
        "subscriptionPlanUid" => null,
        "permissions" => [],
        "IsAlarmDetectEnabled" => false
      ]
    ];
    $response = $this->client->post('organizations/companies', $params);

    if ($response->getStatusCode() === 200) {
      $body = json_decode($response->getBody(), true);

      return $body['data']['instanceUid'];
    } else {
      throw new Exception("VSPC API call was unsuccessful with response code (" . $response->getStatusCode() . ")");
    }
  }

  /**
   * @param string $tenant_name
   * @param string $tenant_description
   * @param int $tenant_resource_quota
   * @param bool $enabled
   * @return string $tenant_result JSON encoded
   */
  private function veeam_create_tenant($tenant_name = FALSE, $tenant_description = FALSE, $tenant_resource_quota = FALSE, $enabled = 0)
  {
    // Create tenant XML request
    // Refer to helpcenter.veeam.com for more information
    $url = 'cloud/tenants';
    $xml_data = $this->create_xml(
      'CreateCloudTenantSpec',
      'CloudTenant',
      $url,
      array(
        'Name'                  => $tenant_name,
        'Description'           => $tenant_description,
        'Password'              => $this->tenant_password,
        'Enabled'               => (int) $enabled,
        'LeaseExpirationDate'   => date('c', strtotime($this->lease_expiration)),
        'Resources'             => $this->backup_resource,
        'ComputeResources'      => $this->replication_resource,
        'ThrottlingEnabled'     => 'true',
        'ThrottlingSpeedLimit'  => 1,
        'ThrottlingSpeedUnit'   => 'MBps',
        'PublicIpCount'         => 0,
        'BackupServerUid'  => $this->backup_server_urn
      )
    );

    // POST XML request to RESTful API
    $response = $this->client->post($url, array('body' => $xml_data, "headers" => array('Content-Type' => 'text/xml')));

    // Wait for tenant create task to finish
    $tenant_task_id = (string) $response->xml()->TaskId;
    $tenant_id = $this->veeam_task_subscriber($tenant_task_id, 'CloudTenant');

    // Send output to web frontend
    $result = array('username' => $this->tenant_name, 'password' => $this->tenant_password, 'quota' => $this->tenant_resource_quota);

    return json_encode($result);
  }

  /**
   * @param string $username
   * @param string $email
   * @param string $full_name
   * @param string $company_name
   * @param int $resource_quota
   * @param bool $enabled
   * @return string $tenant_result JSON encoded
   */
  public function run($username, $email, $full_name, $company_name)
  {
    $config = include('config.php');

    // Retrieving UIDs configured values
    $this->cloud_connect_site = $this->veeam_get_cloud_connect_site($config['vcc_server']);
    $this->cloud_connect_repository = $this->veeam_get_cloud_connect_repository($this->cloud_connect_site, $config['vcc_repository']);

    // Setting description
    $description = $full_name . " - " . $company_name;

    // Creating Company in VSPC
    $this->company = $this->veeam_create_company($company_name, null, "", $email, "", null, null, "", "", $description, "", "", "", null, FALSE);

    echo $this->company;
    // echo $this->veeam_create_tenant($tenant_name, $tenant_description, $tenant_resource_quota, $enabled);
  }
}
