<?php

namespace Webkul\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class TinyMCEController extends Controller
{
    /**
     * Storage folder path.
     */
    private string $storagePath = 'tinymce';

    /**
     * Image types accepted for an editor upload. The stored file extension is
     * always derived from the file real (content-sniffed) MIME type below, never
     * from the client-supplied filename.
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/x-ms-bmp',
    ];

    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /**
     * Upload file from tinymce.
     */
    public function upload(): JsonResponse
    {
        $media = $this->storeMedia();

        if (! empty($media)) {
            return response()->json([
                'location' => $media['file_url'],
            ]);
        }

        return response()->json([]);
    }

    /**
     * Store media.
     */
    public function storeMedia(): array
    {
        if (! request()->hasFile('file')) {
            return [];
        }

        $file = request()->file('file');

        if (! $file instanceof UploadedFile) {
            return [];
        }

        /*
         * SECURITY (CVE-2026-38526): this endpoint previously stored the upload
         * under the CLIENT-supplied extension on the public disk. Because the app
         * is served by PHP built-in web server, which EXECUTES .php files it finds
         * on disk, a .php upload became an authenticated web shell (remote code
         * execution). Require the file to be a real raster image by its sniffed
         * MIME type, and derive the stored extension from that type, so a script
         * can never be written here no matter what it is named. SVG is not accepted
         * via this endpoint (its sanitiser keys off the client filename, which is
         * bypassable); reference an SVG by URL instead.
         */
        $validator = Validator::make(['file' => $file], [
            'file' => [
                'required',
                'file',
                'max:5120',
                'mimetypes:'.implode(',', self::ALLOWED_MIME_TYPES),
            ],
        ]);

        if ($validator->fails()) {
            return [];
        }

        $extension = strtolower((string) $file->guessExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return [];
        }

        $filename = md5($file->getClientOriginalName().microtime(true)).'.'.$extension;

        $path = $file->storeAs($this->storagePath, $filename);

        return [
            'file' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_url' => Storage::url($path),
        ];
    }
}
