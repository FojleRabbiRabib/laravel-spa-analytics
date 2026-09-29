<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Models;

use FojleRabbiRabib\LaravelSpaAnalytics\Database\Factories\VisitorLinkFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(VisitorLinkFactory::class)]
class VisitorLink extends Model
{
    use HasFactory;

    protected $table = 'analytics_visitor_links';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
