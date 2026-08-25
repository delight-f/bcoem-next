<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Custom Categories</h1>

        <p class="print:hidden">
            <a class="btn btn-primary" href="{{ route('admin.specialbest.create') }}">Add a Custom Category</a>
        </p>

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
