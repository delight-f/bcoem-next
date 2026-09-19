{{-- Legacy #availJudgeModal / #availStewardModal
     (judging_tables.admin.php:852-884): lib/admin.lib.php not_assigned().
     Shared by the tables list and the table add/edit form, whose "View... ->
     Not Assigned to a Table" menu items target these ids. --}}
@php($unassignedJudges = $unassignedJudges ?? collect())
@php($unassignedStewards = $unassignedStewards ?? collect())
<div class="modal fade" id="availJudgeModal" tabindex="-1" role="dialog" aria-labelledby="availJudgeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title fw-bold" id="availJudgeModalLabel">Judges Not Assigned to a Table</h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                @if ($unassignedJudges->isEmpty())
                    <p>No judges are currently not assigned to a table.</p>
                @else
                    <table class="table table-bordered">
                        <thead><tr><th>Name</th><th>Judge Rank</th></tr></thead>
                        <tbody>
                            @foreach ($unassignedJudges as $judge)
                                <tr>
                                    <td class="small">{{ $judge['name'] }}</td>
                                    <td class="small">{{ $judge['rank'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="availStewardModal" tabindex="-1" role="dialog" aria-labelledby="availStewardModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title fw-bold" id="availStewardModalLabel">Stewards Not Assigned to a Table</h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                @if ($unassignedStewards->isEmpty())
                    <p>No stewards are currently not assigned to a table.</p>
                @else
                    <table class="table table-bordered">
                        <thead><tr><th>Name</th><th>Judge Rank</th></tr></thead>
                        <tbody>
                            @foreach ($unassignedStewards as $steward)
                                <tr>
                                    <td class="small">{{ $steward['name'] }}</td>
                                    <td class="small">{{ $steward['rank'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
