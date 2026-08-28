<x-public-layout :ctx="$ctx" :show-hero="false">
    @php
        $msgTexts = [
            29 => 'The file has been uploaded successfully. Check the list to verify.',
            30 => 'The file that was attempted to be uploaded is not an accepted file type and/or it exceeds the maximum file size.',
            31 => 'File(s) deleted successfully.',
        ];
    @endphp
    <section class="container mt-6 mb-4">
        <h1>Upload Sponsor Logo Images</h1>

        @if (in_array((int) request('msg'), array_keys($msgTexts), true))
            <div class="alert alert-success">{{ $msgTexts[(int) request('msg')] }}</div>
        @endif

        {{-- Legacy leads both variants with a link to the other
             (upload.admin.php:14,45). --}}
        <p>
            @if ($single)
                If you want to upload multiple images at once, use the
                <a href="{{ url('/admin/upload') }}">enhanced image upload function</a>.
            @else
                The <a href="{{ url('/admin/upload') }}?action=html">single image upload function</a>
                is also available as an alternative to this multiple upload function.
            @endif
        </p>

        <p class="bcoem-admin-element">
            Acceptable file types are .jpg, .jpeg, .png, .svg, .webp, or .gif. Maximum file size is 10 MB.
        </p>

        <form method="post" action="{{ $single ? url('/admin/upload').'?action=html' : url('/admin/upload') }}"
              enctype="multipart/form-data" class="mb-4">
            @csrf
            <input type="file" name="file[]" @if (! $single) multiple @endif class="mb-2">
            <p><input type="submit" class="btn btn-primary"
                      value="{{ $single ? 'Upload Logo Image' : 'Upload Logo Images' }}"></p>
        </form>

        @if ($files !== [])
            <h2>Files in the Directory</h2>
            <table class="table table-zebra table-bordered">
                <thead>
                    <tr>
                        <th>File Name</th>
                        <th>Date/Time Uploaded</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($files as $f)
                        <tr>
                            <td><a data-fancybox="gallery" class="user_images hide-loader" rel="group1"
                                   href="{{ asset('user_images/'.$f['name']) }}" title="{{ $f['name'] }}">{{ $f['name'] }}</a></td>
                            <td>{{ date('l, F j, Y H:i', $f['mtime']) }}</td>
                            <td>
                                {{-- Legacy upload.admin.php: delete is a plain
                                     link to the process image-delete target
                                     (data-confirm handles the prompt). --}}
                                <a class="hide-loader" href="{{ url('/admin/upload/delete') }}?action=delete&filter={{ urlencode($f['name']) }}&go=image&view={{ $single ? 'html' : 'default' }}" data-confirm="Are you sure? This will remove the image named {{ $f['name'] }} from the server."><span class="fa fa-lg fa-trash"></span></a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</x-public-layout>
