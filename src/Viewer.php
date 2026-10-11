<?php

declare(strict_types=1);

namespace Hangar;

/** Der angemeldete Nutzer mit seinen Orgas. */
final class Viewer
{
    /**
     * @param list<array{id:string,slug:string,name:string,iconUrl:?string,role:string,canPlan:bool}> $orgs
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $name,
        public readonly ?string $image,
        public readonly ?string $discordId,
        public readonly array $orgs,
        public readonly ?string $membershipStatus,
        public readonly ?string $csrf,
    ) {
    }

    /** @return list<string> */
    public function orgIds(): array
    {
        return array_column($this->orgs, 'id');
    }

    /** @return array{id:string,slug:string,name:string,iconUrl:?string,role:string,canPlan:bool}|null */
    public function org(string $slug): ?array
    {
        foreach ($this->orgs as $o) {
            if ($o['slug'] === $slug) {
                return $o;
            }
        }
        return null;
    }
}
