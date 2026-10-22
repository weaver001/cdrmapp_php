<?php

namespace App\Services;

class RichText
{
    public function sanitize(string $html): string
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,ul,ol,li,a[href|title],h2,h3,blockquote');
        $config->set('URI.AllowedSchemes', ['https' => true, 'http' => true, 'mailto' => true]);
        $config->set('Cache.SerializerPath', storage_path('framework/cache'));

        return (new \HTMLPurifier($config))->purify($html);
    }
}
