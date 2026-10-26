<?php

return ['fcm' => ['enabled' => env('FCM_ENABLED', false), 'project' => env('FCM_PROJECT_ID'), 'credentials' => env('FCM_SERVICE_ACCOUNT_FILE'), 'credentials_json' => env('FCM_SERVICE_ACCOUNT_JSON')], 'google_maps' => ['web_key' => env('GOOGLE_MAPS_WEB_KEY')]];
