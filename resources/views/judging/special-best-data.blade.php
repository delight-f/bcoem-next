<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Custom Style Entries</h1>

        <div class="bcoem-admin-element hidden-print mb-3">
            {{-- View... dropdown (special_best_data.admin.php:814-825). --}}
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="fa fa-eye"></span> View...
                    <span class="caret"></span>
                </button>
                <ul class="dropdown-menu">
                    <li class="small"><a class="dropdown-item" href="{{ route('admin.specialbest.index') }}">All Custom Categories</a></li>
                    @if ($entriesCount > 0)
                        <li class="small"><a class="dropdown-item" href="{{ route('admin.specialbest.data.index') }}">All Custom Style Entries</a></li>
                    @endif
                </ul>
            </div>

            <div class="btn-group" role="group" aria-label="add-custom-winning">
                <a class="btn btn-default" href="{{ route('admin.specialbest.create') }}"><span class="fa fa-plus-circle"></span> Add a Custom Category</a>
            </div>

            {{-- Add/Edit Entries For... dropdown (special_best_data.admin.php:827-838). --}}
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="fa fa-plus-circle"></span> Add/Edit Entries For...
                    <span class="caret"></span>
                </button>
                <ul class="dropdown-menu">
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

        <p>Custom categories are useful if your competition features unique &ldquo;best of show&rdquo; categories, such as Pro-Am opportunities, Stewards&rsquo; Choice, Best Name, etc.</p>

        @if ($rows->isEmpty())
            <p>There are no entries found in any custom category.</p>
        @else
            <table class="table table-zebra table-bordered">
                <thead>
                    <tr>
                        <th>Custom Style</th>
                        <th>Place</th>
                        <th>Entry</th>
                        <th>Entry Name</th>
                        <th>Brewer</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row->sbi_name }}</td>
                            <td>{{ $row->sbd_place }}</td>
                            <td>{{ str_pad((string) $row->eid, 6, '0', STR_PAD_LEFT) }}</td>
                            <td>{{ $row->brewName }}</td>
                            <td>{{ trim($row->brewerFirstName.' '.$row->brewerLastName) }}</td>
                            <td class="print:hidden">
                                <a href="{{ route('admin.specialbest.data.edit', ['id' => $row->sid]) }}">Edit category entries</a>
                                <form method="post" action="{{ route('admin.specialbest.data.destroy', ['id' => $row->id]) }}" class="inline"
                                      onsubmit="return confirm('Delete this winner? This cannot be undone.');">
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
