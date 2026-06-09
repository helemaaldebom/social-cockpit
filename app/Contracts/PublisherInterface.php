<?php

namespace App\Contracts;

use App\Models\ContentItem;
use Carbon\CarbonInterface;

interface PublisherInterface
{
    public function schedulePost(ContentItem $item, array $publerAccountIds, CarbonInterface $scheduledFor): string;

    public function updatePost(string $publerPostId, ContentItem $item): void;

    public function deletePost(string $publerPostId): void;
}
