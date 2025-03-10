<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;


use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\PalletStatusController;
use App\Models\MenuItem;


Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
	'hostedBy' => env('HOSTED_BY','unknown'),
	'hostedLink' => env('HOSTED_LINK','https://example.com')
    ]);
});

    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->middleware(['auth', 'verified'])->name('dashboard');
    
    Route::get('/qrcode-test', function () {
        return Inertia::render('QRCodeTest');
    })->middleware(['auth', 'verified']);
    
    /*
    $breadcrumb = [
        ['href' => '/dashboard#setup','title' => 'Setup Menu'],
        ['href' => '/order-entry','title' => 'Order Entry'],           
    ];
    */
    
    Route::get('/order-entry', function () {
        return Inertia::render('OrderEntry', 
            ['breadcrumb' => MenuItem::getBreadcrumb('/order-entry')]);
    })->middleware(['auth']);
    
    Route::get('/donation-sorting', function () {
        return Inertia::render('DonationSorting');
    })->middleware(['auth', 'role:4'])->name('entry');
    
    Route::get('/inventory-movement', function () {
        return Inertia::render('PalletLocation');
    })->middleware(['auth', 'role:4'])->name('entry');
    
    Route::get('/pallet-management', function () {
        return Inertia::render('PalletManagement');
    })->middleware(['auth', 'role:4'])->name('entry');
    
    Route::get('/setup/items', function () {
        return Inertia::render('ItemEntry');
    })->middleware(['auth', 'role:4'])->name('setup');
  
    Route::get('/setup/people', function () {
        return Inertia::render('People',
            ['breadcrumb' => MenuItem::getBreadcrumb('/setup/people')]);
    })->middleware(['auth', 'role:4'])->name('setup');
    
    Route::get('/setup/categories', function () {
        return Inertia::render('CategoryEntry');
    })->middleware(['auth', 'role:4'])->name('setup');
    
    Route::get('/setup/locations', function () {
        return Inertia::render('LocationEntry');
    })->middleware(['auth', 'role:4'])->name('setup');
    
    Route::get('/reports/labels', function () {
        return Inertia::render('PrintLabels');
    })->middleware(['auth', 'role:4'])->name('reports');
    
    Route::get('/report/pallet/{id}', 
        [PalletReportController::class, 'generateReport'])
        ->name('report.pallet')
        ->middleware(['auth', 'role:4']);
    
    
    Route::middleware('auth')->group(function () {
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    });


    // Routes accessible  by everyone (including unknown users who just registered with an email address)
    Route::group(['prefix' => 'json','middleware' => ['auth']], function()
    {
        Route::get('/menu-data', [MenuController::class, 'index']);
        
    
        Route::post('/orders', [OrderController::class, 'store']);
        
    
    
        // API route for listing all statuses
        Route::get('/statuses', [StatusController::class, 'index']);
        
        Route::get('/counties', [CountyController::class, 'index']);
        Route::get('/states', [CountyController::class, 'states']);
        
    });
    
    
    // Routes accessible by Volunteer Users
    Route::group(['prefix' => 'json','middleware' => ['auth', 'role:4']], function()
    {
        // People
        Route::get('/people', [PeopleController::class, 'index']);
        Route::post('/people', [PeopleController::class, 'store']);
        Route::put('/people/{id}', [PeopleController::class, 'update']);
        
        // Orders
        Route::get('/orders', [OrderController::class, 'index']);
        
        // Items
        Route::get('/items', [ItemController::class, 'index']);
        Route::post('/items', [ItemController::class, 'store']);
        Route::put('/items/{id}', [ItemController::class, 'update']);
        Route::delete('/items/{id}', [ItemController::class, 'destroy']);
        
        // Units
        Route::get('/units', [UnitController::class, 'index']);
        Route::post('/units', [UnitController::class, 'store']);
        Route::put('/units/{id}', [UnitController::class, 'update']);
        Route::delete('/units/{id}', [UnitController::class, 'destroy']);
        
        // Categories
        Route::get('/categories', [CategoryController::class, 'index']);
    
        // Locations
        Route::get('/locations', [LocationController::class, 'index']);
        
        // Warehouses
        Route::get('/json/warehouses', [WarehouseController::class, 'index']);
        
        // Uses (for Locations)
        Route::get('/uses', [UseController::class, 'index']);
    
        // Pallet Status routes - add these to the appropriate middleware group
        Route::get('/palletstatus', [PalletStatusController::class, 'index']);
        Route::get('/palletstatus/statuses', [PalletStatusController::class, 'statuses']);
        Route::post('/palletstatus', [PalletStatusController::class, 'store']);
        Route::put('/palletstatus/{id}', [PalletStatusController::class, 'update']);
        Route::delete('/palletstatus/{id}', [PalletStatusController::class, 'destroy']);
        
        // ItemTypes
        Route::get('/itemtypes', [ItemTypeController::class, 'index']);
        Route::get('/itemtypes/{mod}', [ItemTypeController::class, 'index']);
    
        
        // PackageTypes
        Route::get('/packagetypes', [PackageTypeController::class, 'index']);
    
        
        // Orders (protected routes)
        Route::put('/orders/{id}', [OrderController::class, 'update']);
        Route::delete('/orders/{id}', [OrderController::class, 'destroy']);
        
        // Donations
        Route::get('/donations', [DonationController::class, 'index']);
        Route::post('/donations', [DonationController::class, 'store']);
        Route::put('/donations/{id}', [DonationController::class, 'update']);
        Route::delete('/donations/{id}', [DonationController::class, 'destroy']);
        
        // Pallets
        Route::get('/pallets', [PalletController::class, 'index']);
        Route::get('/pallets/{status}', [PalletController::class, 'index']);
        Route::post('/pallets', [PalletController::class, 'store']);
        Route::put('/pallets/{id}', [PalletController::class, 'update']);
        Route::delete('/pallets/{id}', [PalletController::class, 'destroy']);
        
        // Roles (for People
        Route::get('/roles', [RoleController::class, 'index']);
        
        // Counties
        Route::post('/counties', [CountyController::class, 'store']);
        Route::put('/counties/{id}', [CountyController::class, 'update']);
        Route::delete('/counties/{id}', [CountyController::class, 'destroy']);
    
    });
    
    // Routes accessible only by Administrator (Super Users)
    Route::group(['prefix' => 'json','middleware' => ['auth', 'role:32768']], function()
    {
        

        Route::delete('/people/{id}', [PeopleController::class, 'destroy']);
        
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{id}', [CategoryController::class, 'update']);
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
        
        Route::post('/locations', [LocationController::class, 'store']);
        Route::put('/locations/{id}', [LocationController::class, 'update']);
        Route::delete('/locations/{id}', [LocationController::class, 'destroy']);
        
        Route::post('/uses', [UseController::class, 'store']);
        Route::put('/uses/{id}', [UseController::class, 'update']);
        Route::delete('/uses/{id}', [UseController::class, 'destroy']);
        
        Route::post('/itemtypes', [ItemTypeController::class, 'store']);
        Route::put('/itemtypes/{id}', [ItemTypeController::class, 'update']);
        Route::delete('/itemtypes/{id}', [ItemTypeController::class, 'destroy']);
        
        Route::post('/packagetypes', [PackageTypeController::class, 'store']);
        Route::put('/packagetypes/{id}', [PackageTypeController::class, 'update']);
        Route::delete('/packagetypes/{id}', [PackageTypeController::class, 'destroy']);
        
        Route::post('/roles', [RoleController::class, 'store']);
        Route::put('/roles/{id}', [RoleController::class, 'update']);
        Route::delete('/roles/{id}', [RoleController::class, 'destroy']);
        
        // Warehouses
        Route::post('/json/warehouses', [WarehouseController::class, 'store']);
        Route::put('/json/warehouses/{id}', [WarehouseController::class, 'update']);
        Route::delete('/json/warehouses/{id}', [WarehouseController::class, 'destroy']);
 
    });


require __DIR__.'/auth.php';
