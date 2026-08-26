<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Custom Categories</h1>
        <p>Custom categories are useful if your competition features unique &ldquo;best of show&rdquo; categories, such as Pro-Am opportunities, Stewards&rsquo; Choice, Best Name, etc.</p>

        <div class="bcoem-admin-element hidden-print mb-3">
            {{-- View... dropdown (special_best.admin.php:777-790). On the
                 default index the only item is "All Custom Category Entries",
                 enabled when winner rows exist. --}}
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="fa fa-eye"></span> View...
                    <span class="caret"></span>
                </button>
                <ul class="dropdown-menu">
                    @if ($entriesCount > 0)
                        <li class="small"><a class="dropdown-item" href="{{ route('admin.specialbest.data.index') }}">All Custom Category Entries</a></li>
                    @else
                        <li class="small"><span class="dropdown-item disabled text-muted">All Custom Category Entries</span></li>
                    @endif
                </ul>
            </div>

            <div class="btn-group" role="group" aria-label="add-custom-winning">
                <a class="btn btn-default" href="{{ route('admin.specialbest.create') }}"><span class="fa fa-plus-circle"></span> Add a Custom Category</a>
            </div>

            {{-- Add/Edit Entries For... dropdown (special_best.admin.php:795-806;
                 lib/admin.lib.php score_custom_winning_choose()). --}}
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="fa fa-plus-circle"></span> Add/Edit Entries For...
                    <span class="caret"></span>
                </button>
                <ul class="dropdown-menu" aria-labelledby="scoresMenu2">
                    @forelse ($entryDropdown as $item)
                        <li class="small"><a class="dropdown-item" href="{{ route('admin.specialbest.data.edit', ['id' => $item['id']]) }}">{{ $item['name'] }}</a></li>
                    @empty
                        <li class="small disabled"><a class="dropdown-item" href="#">No custom categories have been defined</a></li>
                        <li role="separator" class="divider"></li>
                        <li class="small"><a class="dropdown-item" href="{{ route('admin.specialbest.create') }}">Add a Custom Category</a></li>
                    @endforelse
                </ul>
            </div>
        </div>

        @if ($categories->isEmpty())
            <p>No custom categories were found in the database.</p>
        @else
            <table class="table table-zebra table-bordered">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Places</th>
                        <th>Rank</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($categories as $category)
                        @php($dataCount = \Illuminate\Support\Facades\DB::table('special_best_data')->where('sid', $category->id)->count())
                        <tr>
                            <td>{{ $category->sbi_name }}</td>
                            <td>{{ $category->sbi_description }}</td>
                            <td>{{ $category->sbi_places }}</td>
                            <td>{{ $category->sbi_rank }}</td>
                            <td class="print:hidden">
                                <a href="{{ route('admin.specialbest.edit', ['id' => $category->id]) }}">Edit</a>
                                &middot;
                                <a href="{{ route($dataCount > 0 ? 'admin.specialbest.data.edit' : 'admin.specialbest.data.edit', ['id' => $category->id]) }}">Entries</a>
                                <form method="post" action="{{ route('admin.specialbest.destroy', ['id' => $category->id]) }}" class="inline"
                                      onsubmit="return confirm('Delete {{ $category->sbi_name }}? All associated data will be deleted as well.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-link btn-sm p-0">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</x-public-layout>
