@extends('layouts.admin.app')

@section('title', translate('Channels_Hub'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Channels_Hub')" :subtitle="translate('channels_hub_subtitle')">
            <x-slot:actions>
                <a class="btn btn-primary btn-sm" href="{{ route('admin.sales-channels.accounts.create') }}">{{ translate('add_channel_account') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.products.index') }}">{{ translate('Products_&_Channels') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.inventory.index') }}">{{ translate('Stock_by_Location') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.orders.index') }}">{{ translate('Unified_Orders') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.routing.index') }}">{{ translate('Dynamic_Routing') }}</a>
                {{-- What waits for a person, carrying its own count: a page nobody can find is the hole the collector closes. --}}
                <a class="btn btn-outline-warning btn-sm" href="{{ route('admin.sales-channels.attention') }}">
                    {{ translate('Needs_A_Person') }}@if (($attentionWaiting ?? 0) > 0) <span class="badge bg-danger">{{ $attentionWaiting }}</span>@endif
                </a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.logs.index') }}">{{ translate('Channel_API_Logs') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        @include('admin-views.sales-channels.partials._global-switches')

        @if ($cards === [])
            <x-k.card>
                <x-k.empty icon="store" :title="translate('no_channel_connected_yet')" :text="translate('no_channel_connected_yet_help')">
                    <x-slot:action>
                        <a class="btn btn-primary btn-sm" href="{{ route('admin.sales-channels.accounts.create') }}">{{ translate('connect_your_first_channel') }}</a>
                    </x-slot:action>
                </x-k.empty>
            </x-k.card>
        @else
            @include('admin-views.sales-channels.partials._attention')

            <div class="row g-3">
                @foreach ($cards as $card)
                    <div class="col-xl-6">
                        @include('admin-views.sales-channels.partials._account-card')
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection
