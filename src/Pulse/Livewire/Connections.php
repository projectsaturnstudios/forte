<?php

namespace ProjectSaturnStudios\Forte\Pulse\Livewire;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Laravel\Pulse\Livewire\Card;
use Laravel\Pulse\Livewire\Concerns\HasPeriod;
use Laravel\Pulse\Livewire\Concerns\RemembersQueries;
use ProjectSaturnStudios\Forte\Pulse\Recorders\ForteConnections;
use Livewire\Attributes\Lazy;

class Connections extends Card
{
    use HasPeriod, RemembersQueries;

    /**
     * The graph colors.
     */
    public array $colors = [
        'avg' => '#10b981',
        'max' => '#9333ea',
    ];

    /**
     * Render the component.
     */
    #[Lazy]
    public function render()
    {
        [$connections, $time, $runAt] = $this->remember(function () {
            return with($this->graph(['reverb_connections'], 'max'), function ($max) {
                return $this->graph(['reverb_connections'], 'avg')->map(fn ($readings, $app) => collect([
                    'reverb_connections:avg' => $readings['reverb_connections'],
                    'reverb_connections:max' => $max[$app]['reverb_connections'],
                ]));
            });
        });

        if (Request::hasHeader('X-Livewire')) {
            $this->dispatch('reverb-connections-chart-update', connections: $connections);
        }

        return View::make('forte::livewire.connections', [
            'connections' => $connections,
            'time' => $time,
            'runAt' => $runAt,
            'config' => Config::get('pulse.recorders.'.ForteConnections::class),
        ]);
    }

    /**
     * Define any CSS that should be loaded for the component.
     *
     * @return string|Htmlable|array<int, string|Htmlable>|null
     */
    protected function css(): HtmlString
    {
        return new HtmlString(
            '<style>'.
            collect($this->colors)->map(fn ($color) => '.bg-\\[\\'.$color.'\\]{background-color:'.$color.'}')->join('').
            '</style>'
        );
    }
}
