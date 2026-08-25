<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Custom Style Entries</h1>

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
