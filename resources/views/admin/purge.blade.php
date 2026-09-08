<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Purge / Reset Data</h1>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        <div class="alert alert-warning">
            <strong>Every action on this page permanently destroys data with NO archive copy.</strong>
            Unpaid/unconfirmed/stale entries, scores, BOS placements, special-best data, evaluations, and payments
            deleted here cannot be recovered from within the application — hosting-layer backups are the only safety net.
        </div>

        <div class="row g-4">
            @foreach ([
                'unpaid' => ['Purge Unpaid Entries', 'Deletes every brewing row that was never marked paid.'],
                'unconfirmed' => ['Purge Unconfirmed / Incomplete Entries', 'Deletes unconfirmed entries and entries whose style requires special-ingredient data that was never supplied.'],
                'participants' => ['Purge Participants', 'Deletes non-admin accounts (optionally only those created before a threshold date) with their brewer profiles, entries, staff rows and assignments.'],
            ] as $flow => [$title, $description])
                <x-purge-action :ctx="$ctx" :flow="$flow" :title="$title" :description="$description" :threshold="true"/>
            <x-purge-action :ctx="$ctx" flow="cleanup" title="Clean-Up Data"
                description="Runs the legacy data_cleanup integrity pass (orphans, stray children, counters)."/>
            <x-purge-action :ctx="$ctx" flow="confirmed" title="Confirm All Unconfirmed"
                description="Marks every unconfirmed entry confirmed (legacy confirm-all)."/>

            <x-purge-action :ctx="$ctx" flow="entries" title="Purge All Entries"
                description="Truncates brewing plus every child table (scores, BOS, special-best data, evaluation, payments) regardless of state."/>
            <x-purge-action :ctx="$ctx" flow="scores" title="Reset Scores"
                description="Truncates judging_scores, judging_scores_bos and special_best_data."/>
            <x-purge-action :ctx="$ctx" flow="tables" title="Reset Tables & Flights"
                description="Truncates judging_tables, judging_assignments, judging_flights, judging_scores and special_best_data."/>
            <x-purge-action :ctx="$ctx" flow="custom" title="Reset Special-Best Data"
                description="Truncates special_best_info and special_best_data."/>
            <x-purge-action :ctx="$ctx" flow="judge-assignments" title="Purge Judge Assignments"
                description="Deletes all judge (J) rows from judging_assignments."/>
            <x-purge-action :ctx="$ctx" flow="steward-assignments" title="Purge Steward Assignments"
                description="Deletes all steward (S) rows from judging_assignments."/>
            <x-purge-action :ctx="$ctx" flow="availability" title="Reset Staff Availability & Assignments"
                description="Clears judge/steward flags on every brewer, truncates staff and judging_assignments, re-seeds location availability marks."/>
            @if ($hasEvaluation)
                <x-purge-action :ctx="$ctx" flow="evaluation" title="Purge Evaluations"
                    description="Truncates the evaluation table."/>
            @endif
            @if ($hasPayments)
                <x-purge-action :ctx="$ctx" flow="payments" title="Purge Payments"
                    description="Truncates the payments table." :threshold="true"/>
            @endif
            <x-purge-action :ctx="$ctx" flow="purge-all" title="Purge ALL Data"
                description="Runs every purge flow in sequence (entries, participants, scores, tables, special-best, availability, evaluations, payments). Irreversible." :threshold="true"/>
            @endforeach
        </div>
    </section>
</x-public-layout>
