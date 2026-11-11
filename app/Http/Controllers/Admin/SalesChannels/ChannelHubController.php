<?php

namespace App\Http\Controllers\Admin\SalesChannels;

use App\Http\Controllers\Admin\SalesChannels\Support\HubSummary;
use App\Http\Controllers\Controller;
use App\Services\SalesChannels\Attention\AttentionCollector;
use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Support\OperationalCostsSwitch;
use Illuminate\View\View;

/** The hub: the switches that govern every channel call, one card per connected account, and what needs a person. */
class ChannelHubController extends Controller
{
    public function __construct(
        private readonly HubSummary $summary,
        private readonly ChannelLabels $labels,
        private readonly AttentionCollector $waiting,
    ) {
    }

    public function index(): View
    {
        $accounts = SalesChannelAccount::orderBy('id')->get();

        return view('admin-views.sales-channels.index', [
            'labels' => $this->labels,
            'globalEnabled' => (bool) config('sales_channels.enabled'),
            'externalWritesEnabled' => (bool) config('sales_channels.external_writes_enabled'),
            'managedDispatchEnabled' => (bool) config('sales_channels.managed_dispatch_enabled'),
            'costsEnabled' => OperationalCostsSwitch::enabled(),
            'costsScheduleEnabled' => OperationalCostsSwitch::scheduleSwitchedOn(),
            'cards' => $this->summary->cards($accounts),
            'attention' => $this->summary->attention($accounts),
            // How many rows are waiting for a person, across all nine sources, so the hub carries the count rather than
            // leaving the page to be found. Cheap: nine counts, no row loaded.
            'attentionWaiting' => $this->waiting->summary()['total'],
        ]);
    }
}
