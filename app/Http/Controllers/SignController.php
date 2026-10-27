<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Support\SignLayout;
use App\Support\SignTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Warehouse signs on Letter paper (Setup > Signs, /setup/signs): a
 * ready-made one from App\Support\SignTemplates, or any title and smaller text,
 * with an optional safety header and arrows. SignLayout fits the text; the PDF
 * (reports/sign.blade.php) and the page's preview are the same view, so what
 * the preview shows is what prints. Open to anyone signed in, like the Help
 * guides — a sign holds nothing but what the person typed.
 */
class SignController extends Controller
{
    public static function rules(): array
    {
        return [
            'title' => 'required|string|max:120',
            'subtitle' => 'nullable|string|max:400',
            'header' => 'nullable|in:'.implode(',', array_keys(SignLayout::HEADERS)),
            'orientation' => 'required|in:'.implode(',', SignLayout::ORIENTATIONS),
            'arrows' => 'nullable|array',
            'arrows.*' => 'in:'.implode(',', array_keys(SignLayout::ARROWS)),
        ];
    }

    public function page()
    {
        return Inertia::render('Signs', [
            'breadcrumb' => MenuItem::getBreadcrumb('/setup/signs'),
            'groups' => SignTemplates::groups(),
            'headers' => collect(SignLayout::HEADERS)->map(fn ($h) => $h['label']),
        ]);
    }

    public function pdf(Request $request)
    {
        return $this->builder($request->validate(self::rules()));
    }

    /** The sign as an HTML page, for the Signs page's preview. */
    public function preview(Request $request)
    {
        $sign = $request->validate(self::rules());

        return response()->view('reports.sign', ['layout' => SignLayout::make($sign), 'preview' => true, 'title' => $sign['title']]);
    }

    /** The bundled sign fonts, for the preview (the PDF reads them from disk). */
    public function font(string $file)
    {
        return response()->file(resource_path('fonts/liberation/'.$file), [
            'Content-Type' => 'font/ttf',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    /** Unrendered PDF — shared by the download route and the print queue. */
    public function builder(array $sign): PdfBuilder
    {
        $layout = SignLayout::make($sign);
        $pdf = Pdf::view('reports.sign', ['layout' => $layout, 'preview' => false, 'title' => $sign['title']])
            ->driver('weasyprint')
            ->format('letter')
            ->name('sign-'.(Str::slug(Str::limit($sign['title'], 40, '')) ?: 'sign').'.pdf');

        return $layout['orientation'] === 'landscape' ? $pdf->landscape() : $pdf;
    }
}
