<?php

namespace ProjectSaturnStudios\Forte\Contracts;

use Illuminate\Support\Collection;
use ProjectSaturnStudios\Forte\Application;
use ProjectSaturnStudios\Forte\Exceptions\InvalidApplication;

interface ApplicationProvider
{
    /**
     * Get all of the configured applications as Application instances.
     *
     * @return Collection<Application>
     */
    public function all(): Collection;

    /**
     * Find an application instance by ID.
     *
     * @throws InvalidApplication
     */
    public function findById(string $id): Application;

    /**
     * Find an application instance by key.
     *
     * @throws InvalidApplication
     */
    public function findByKey(string $key): Application;
}
