<?php

return [
    // Native OCI REST client tuning.
    'timeout' => (int) env('OCI_HTTP_TIMEOUT', 30),
    'retry_max' => (int) env('OCI_HTTP_RETRY_MAX', 3),
    // How long a freshly provisioned VM may take to accept SSH before validation runs.
    'ssh_wait_seconds' => (int) env('OCI_SSH_WAIT_SECONDS', 300),
    'terraform_version' => env('OCI_TERRAFORM_VERSION', '1.5.x'),

    'endpoints' => [
        'iaas' => 'https://iaas.{region}.oraclecloud.com',
        'identity' => 'https://identity.{region}.oci.oraclecloud.com',
        'telemetry' => 'https://telemetry.{region}.oraclecloud.com',
        'resourcemanager' => 'https://resourcemanager.{region}.oraclecloud.com',
        'loadbalancer' => 'https://iaas.{region}.oraclecloud.com',
        'database' => 'https://database.{region}.oraclecloud.com',
        'objectstorage' => 'https://objectstorage.{region}.oraclecloud.com',
    ],

    // Canonical commercial regions (frozen from the SDK list). listRegions() reads this.
    'regions' => [
        'sa-saopaulo-1', 'sa-vinhedo-1', 'sa-santiago-1', 'sa-bogota-1', 'sa-valparaiso-1',
        'us-ashburn-1', 'us-phoenix-1', 'us-sanjose-1', 'us-chicago-1',
        'ca-toronto-1', 'ca-montreal-1', 'mx-queretaro-1', 'mx-monterrey-1',
        'uk-london-1', 'uk-cardiff-1',
        'eu-frankfurt-1', 'eu-amsterdam-1', 'eu-zurich-1', 'eu-madrid-1',
        'eu-paris-1', 'eu-marseille-1', 'eu-milan-1', 'eu-stockholm-1',
        'ap-tokyo-1', 'ap-osaka-1', 'ap-seoul-1', 'ap-chuncheon-1',
        'ap-mumbai-1', 'ap-hyderabad-1', 'ap-singapore-1', 'ap-singapore-2',
        'ap-sydney-1', 'ap-melbourne-1',
        'me-jeddah-1', 'me-dubai-1', 'me-abudhabi-1', 'il-jerusalem-1',
        'af-johannesburg-1',
    ],

];
