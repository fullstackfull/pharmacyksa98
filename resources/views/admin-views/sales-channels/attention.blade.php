@extends('layouts.admin.app')

@section('title', translate('Needs_A_Person'))

@section('content')
    @php
        $toneOf = static fn (bool $late): string => $late ? 'danger' : 'warning';
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Needs_A_Person')" :subtitle="translate('attention_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.index') }}">{{ translate('Channels_Hub') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        {{-- The one thing a person has to understand before touching this page. --}}
        <div class="alert alert-warning fs-12" role="note">{{ translate('attention_nothing_here_is_sent_again_note') }}</div>
        <div class="alert alert-info fs-12" role="note">{{ translate('attention_a_review_changes_no_operation_note') }}</div>

        <div class="row g-2 mb-3">
            <div class="col-md-3">
                <x-k.card>
                    <div class="fs-12 text-muted">{{ translate('waiting_in_total') }}</div>
                    <div class="h3 mb-0">{{ $summary['total'] }}</div>
                </x-k.card>
            </div>
            <div class="col-md-3">
                <x-k.card>
                    <div class="fs-12 text-muted">{{ translate('waiting_too_long') }}</div>
                    <div class="h3 mb-0 text-danger">{{ $summary['late'] }}</div>
                </x-k.card>
            </div>
            <div class="col-md-6">
                <x-k.card>
                    <div class="fs-12 text-muted mb-1">{{ translate('by_area') }}</div>
                    @forelse ($summary['by_domain'] as $domain => $count)
                        <span class="badge bg-light text-dark me-1">{{ translate('attention_domain_' . $domain) }}: {{ $count }}</span>
                    @empty
                        <span class="fs-12">{{ translate('nothing_is_waiting') }}</span>
                    @endforelse
                </x-k.card>
            </div>
        </div>

        <x-k.card class="mb-3">
            <form method="GET" action="{{ route('admin.sales-channels.attention') }}" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fs-12" for="attention-source">{{ translate('where_it_is_waiting') }}</label>
                    <select class="form-select form-select-sm" id="attention-source" name="source">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($sources as $source)
                            <option value="{{ $source->key }}" @selected($chosen === $source->key)>
                                {{ translate($source->label()) }} ({{ $summary['by_source'][$source->key] ?? 0 }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary btn-sm w-100" type="submit">{{ translate('filter') }}</button>
                </div>
            </form>
        </x-k.card>

        <x-k.card :padded="false">
            <div class="k-table-wrap table-responsive">
                <table class="k-table align-middle">
                    <thead>
                        <tr>
                            <th>{{ translate('where') }}</th>
                            <th>{{ translate('reference') }}</th>
                            <th>{{ translate('state') }}</th>
                            <th>{{ translate('reason') }}</th>
                            <th>{{ translate('waiting_since') }}</th>
                            <th>{{ translate('what_you_may_do') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr>
                                <td>
                                    <div>{{ translate('attention_source_' . $item->source) }}</div>
                                    <div class="fs-12 text-muted">{{ translate('attention_domain_' . $item->domain) }}@if ($item->accountId && isset($accounts[$item->accountId])) · {{ $accounts[$item->accountId] }}@endif</div>
                                </td>
                                <td>
                                    <code class="fs-12">{{ $item->reference ?? '—' }}</code>
                                    @if ($item->orderReference)<div class="fs-12 text-muted">{{ translate('order') }}: {{ $item->orderReference }}</div>@endif
                                </td>
                                <td><span class="badge bg-{{ $toneOf($item->isLate()) }}">{{ translate('attention_state_' . $item->state) }}</span></td>
                                <td>
                                    {{-- Printed as TEXT. A reason is a code this version may not know, and an unknown code is shown as it came rather than becoming a translation key. --}}
                                    <div class="fs-12">{{ $item->reason ?? '—' }}</div>
                                    @if ($item->detail)<div class="fs-12 text-muted">{{ str($item->detail)->limit(160) }}</div>@endif
                                </td>
                                <td class="fs-12">
                                    {{ $item->since ? $item->since->diffForHumans() : translate('not_recorded') }}
                                    @if ($item->isLate())<div class="text-danger">{{ translate('nobody_has_looked_at_this') }}</div>@endif
                                </td>
                                <td>
                                    @if (!$item->retryable && $item->whyNotRetryable)
                                        <div class="fs-12 text-muted mb-1">{{ translate($item->whyNotRetryable) }}</div>
                                    @endif

                                    @if ($item->reviewedBy)
                                        {{-- Somebody is already on it. Said here so two people do not start over, and the operation's own state is still whatever it was. --}}
                                        <div class="fs-12 text-info mb-1">{{ translate('somebody_is_already_looking') }}: {{ translate('attention_outcome_' . $item->reviewOutcome) }}</div>
                                    @endif

                                    @if ($item->allows('review') && $reviewsReady)
                                        <form method="POST" action="{{ route('admin.sales-channels.attention.act', ['source' => $item->source, 'id' => $item->id, 'action' => 'review']) }}" class="d-grid gap-1">
                                            @csrf
                                            {{-- WHAT THEY SAW, from a fixed list. Free text is the note; the outcome is not free text, because a word nobody can group is not a record. --}}
                                            <select class="form-select form-select-sm" name="outcome" required aria-label="{{ translate('what_did_you_see_at_the_channel') }}">
                                                <option value="">{{ translate('what_did_you_see_at_the_channel') }}</option>
                                                @foreach ($outcomes as $outcome)<option value="{{ $outcome }}">{{ translate('attention_outcome_' . $outcome) }}</option>@endforeach
                                            </select>
                                            <input class="form-control form-control-sm" type="text" name="note" required maxlength="2000"
                                                   placeholder="{{ translate('what_did_you_find_at_the_channel') }}" aria-label="{{ translate('what_did_you_find_at_the_channel') }}">
                                            <input class="form-control form-control-sm" type="text" name="evidence" maxlength="191"
                                                   placeholder="{{ translate('the_channels_own_reference_if_any') }}" aria-label="{{ translate('the_channels_own_reference_if_any') }}">
                                            <div class="form-check fs-12">
                                                <input class="form-check-input" type="checkbox" name="close" value="1" id="close-{{ $item->source }}-{{ $item->id }}">
                                                <label class="form-check-label" for="close-{{ $item->source }}-{{ $item->id }}">{{ translate('close_this_alert_the_operation_is_unchanged') }}</label>
                                            </div>
                                            <button class="btn btn-outline-primary btn-sm text-nowrap" type="submit">{{ translate('record_what_i_saw') }}</button>
                                        </form>
                                    @elseif ($item->allows('review'))
                                        <div class="fs-12 text-muted">{{ translate('attention_reviews_table_not_migrated') }}</div>
                                    @endif

                                    @if ($item->allows('put_back'))
                                        <form method="POST" action="{{ route('admin.sales-channels.attention.act', ['source' => $item->source, 'id' => $item->id, 'action' => 'put_back']) }}" class="d-flex gap-1 mt-1">
                                            @csrf
                                            <input class="form-control form-control-sm" type="text" name="note" required maxlength="1000"
                                                   placeholder="{{ translate('why_is_it_safe_to_work_again') }}" aria-label="{{ translate('why_is_it_safe_to_work_again') }}">
                                            <button class="btn btn-outline-secondary btn-sm text-nowrap" type="submit">{{ translate('put_back_in_the_queue') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center fs-12 text-muted py-4">{{ translate('nothing_is_waiting_for_anybody') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-k.card>
    </div>
@endsection
