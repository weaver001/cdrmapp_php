<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicPage extends Model
{
    public const TYPES = ['services', 'announcements', 'team-members', 'faqs', 'contact-details'];

    public const LABELS = ['services' => 'Services', 'announcements' => 'Announcements', 'team-members' => 'Our team', 'faqs' => 'FAQs', 'contact-details' => 'Contact'];

    public const EDITABLE = ['eyebrow', 'heading', 'introduction', 'form_heading', 'form_introduction'];

    protected $guarded = [];

    public static function defaults(string $type): array
    {
        return [
            'eyebrow' => 'Citizen disaster response',
            'heading' => self::LABELS[$type],
            'introduction' => match ($type) {
                'services' => 'Explore our community safety and emergency support services.',
                'announcements' => 'Stay informed with official community notices and updates.',
                'team-members' => 'Meet the people working together for a safer community.',
                'faqs' => 'Find answers to common questions about our services and incident reporting.',
                'contact-details' => 'Find official contact information and send us a non-emergency message.',
            },
            'form_heading' => $type === 'contact-details' ? 'Send a non-emergency message' : null,
            'form_introduction' => $type === 'contact-details' ? 'Do not use this form to report an urgent emergency.' : null,
        ];
    }

    public static function forType(string $type): self
    {
        return self::firstOrNew(['type' => $type], self::defaults($type));
    }
}
