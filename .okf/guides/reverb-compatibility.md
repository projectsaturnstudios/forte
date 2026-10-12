---
type: Guide
title: Reverb compatibility
description: Env fallback, config/reverb.php adoption, REVERB_SERVER=reverb, broadcasting driver, Pulse aliases, forte:install.
resource: src/ForteServiceProvider.php
tags: [forte, reverb, migration, config, pulse]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-12T03:45:26Z }
sources:
  - id: config
    resource: config/forte.php
    title: config/forte.php
  - id: provider
    resource: src/ForteServiceProvider.php
    title: ForteServiceProvider.php
  - id: manager
    resource: src/ServerProviderManager.php
    title: ServerProviderManager.php
  - id: install
    resource: src/Console/Commands/InstallCommand.php
    title: InstallCommand.php
  - id: tests
    resource: tests/Unit/ReverbConfigTest.php
    title: ReverbConfigTest.php
---

# Reverb compatibility

Goal: Reverb app swaps package + start command, nothing else. Each item below is a place that broke or would break that.

## Env

Every key `env('FORTE_X', env('REVERB_X', default))`: Reverb `.env` works untouched, `FORTE_X` wins when both set. `FORTE_LOOP` has no Reverb twin. `REVERB_SERVER=reverb` → `ServerProviderManager` maps `reverb` → `forte`.

## config/reverb.php

Laravel loads every file in `config/`, so a published `config/reverb.php` is in `config('reverb')`. `ForteServiceProvider::adoptReverbConfiguration()` (register, after merge): when `config('reverb')` exists, `config/forte.php` is not published, config not cached → `forte.default` ← reverb's, `forte.servers.forte` ← `array_replace_recursive(forte default, reverb.servers.reverb)` (keeps `loop`), `forte.apps` ← reverb's. Published `config/forte.php` → it alone counts. `config:cache` stores the adopted values.

## Broadcasting + Echo

`reverb` broadcaster is Laravel's (Pusher client), reads `REVERB_*`. Forte serves the same Pusher protocol + API → no change to `config/broadcasting.php`, Echo, `VITE_REVERB_*`.

## Pulse

* Livewire cards registered as `forte.*` and `reverb.*` → existing dashboards render.
* Recorder classes: Reverb's docs put `Laravel\Reverb\Pulse\Recorders\ReverbConnections`/`ReverbMessages` in `config/pulse.php`. `ForteServiceProvider::REVERB_RECORDERS` + an autoloader appended after Composer's → `class_alias` to Forte's recorders. Appended last: installed laravel/reverb still loads its own. Registered once per process.
* Recorded types keep Reverb's names (`reverb_connections`, `reverb_message:*`) → history continues.

## forte:install

Fork of `reverb:install`: writes `REVERB_*` + `VITE_REVERB_*` (Laravel and Echo read those), publishes `forte-config`, adds `reverb` broadcasting connection if missing, `install:broadcasting --reverb --without-reverb` (else Laravel `composer require`s laravel/reverb), sets `BROADCAST_CONNECTION=reverb` on confirm, then names the loop extension to install if missing.

## Commands

`forte:start` (Reverb's flags + `--loop`), `forte:restart` (cache signal, as Reverb). Reloads registered for `forte:restart`.
