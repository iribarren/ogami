<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * What a draft remembers about an item it copied from a library pack: the section and key of the
 * item in the draft, the key of the pack and the hash of the pack item at the time (ADR 0033).
 * A later hash of the pack item or of the draft item tells whether either changed.
 */
final readonly class LibraryImport
{
    public function __construct(
        public LibrarySection $section,
        public string $key,
        public string $pack,
        public string $hash,
    ) {
    }

    /**
     * Reads the stored imports: a list of {section, key, pack, hash}. Anything else is a corrupted
     * row and fails closed, so no command acts on a guess.
     *
     * @return list<self>
     *
     * @throws \UnexpectedValueException when the value is not a well-formed list of imports
     */
    public static function listFromStored(mixed $stored): array
    {
        if (!\is_array($stored) || !array_is_list($stored)) {
            throw new \UnexpectedValueException('The stored library imports must be a list.');
        }

        $imports = [];
        foreach ($stored as $index => $import) {
            $section = \is_array($import) && \is_string($import['section'] ?? null) ? LibrarySection::tryFrom($import['section']) : null;
            if (!$section instanceof LibrarySection || !\is_string($import['key'] ?? null) || !\is_string($import['pack'] ?? null) || !\is_string($import['hash'] ?? null) || 1 !== preg_match('/^[0-9a-f]{64}$/', $import['hash'])) {
                throw new \UnexpectedValueException(\sprintf('The stored library import %d must have a section, a key, a pack and a sha256 hash.', $index));
            }

            $imports[] = new self($section, $import['key'], $import['pack'], $import['hash']);
        }

        return $imports;
    }

    public function ref(): LibraryItemRef
    {
        return new LibraryItemRef($this->section, $this->key);
    }

    /**
     * @return array{section: string, key: string, pack: string, hash: string}
     */
    public function toArray(): array
    {
        return ['section' => $this->section->value, 'key' => $this->key, 'pack' => $this->pack, 'hash' => $this->hash];
    }
}
