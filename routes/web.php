<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\clients\HomeController;
use App\Http\Controllers\clients\AboutController;
use App\Http\Controllers\clients\ContactController;
use App\Http\Controllers\clients\BookingController;
use App\Http\Controllers\clients\DestinationController;
use App\Http\Controllers\clients\TravelGuidesController;
use App\Http\Controllers\clients\ToursController;
use App\Http\Controllers\clients\TourDetailController;
use App\Http\Controllers\clients\BlogController;
use App\Http\Controllers\clients\BlogDetailController;
use App\Http\Controllers\clients\LoginController;
use App\Http\Controllers\clients\SearchController;
use App\Http\Controllers\clients\UserProfileController;
use App\Http\Controllers\clients\TourBookedController;
use App\Http\Controllers\clients\MyTourController;
use App\Http\Controllers\admin\LoginAdminController;
use App\Http\Controllers\admin\ToursManagementController;

// Route::get('/', function () {
//     return view('home');
// });
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/about', [AboutController::class, 'index'])->name('about');
Route::get('/contact', [ContactController::class, 'index'])->name('contact');
Route::get('/destination', [DestinationController::class, 'index'])->name('destination');
Route::get('/travel-guides', [TravelGuidesController::class, 'index'])->name('team');
Route::get('/tour-detail/{id?}', [TourDetailController::class, 'index'])->name('tour-detail');
Route::get('/blogs', [BlogController::class, 'index'])->name('blogs');
Route::get('/blog-detail', [BlogDetailController::class, 'index'])->name('blog-detail');
Route::get('/search', [SearchController::class, 'index'])->name('search');
Route::get('/search-voice-text', [SearchController::class, 'searchTours'])->name('search-voice-text');




//Đăng nhập, đăng ký, đăng xuất
Route::get('/login', [LoginController::class, 'index'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->name('user-login');
Route::post('/register', [LoginController::class, 'register'])->name('register');
Route::get('/logout', [LoginController::class, 'logout'])->name('logout');



//tours, filter tours, tour detail
Route::get('/tours', [ToursController::class, 'index'])->name('tours');
Route::get('/filter-tours', [ToursController::class, 'filterTours'])->name('filter-tours');




//infor
Route::get('/user-profile', [UserProfileController::class, 'index'])->name('user-profile');
Route::post('/user-profile', [UserProfileController::class, 'update'])->name('update-user-profile');
Route::post('/change-password-profile', [UserProfileController::class, 'changePassword'])->name('change-password');
Route::post('/change-avatar-profile', [UserProfileController::class, 'changeAvatar'])->name('change-avatar');


//booking
Route::post('/booking/{id?}', [BookingController::class, 'index'])->name('booking');
Route::post('/create-booking', [BookingController::class, 'createBooking'])->name('create-booking');

//trả bằng momo
Route::post('/create-momo-payment', [BookingController::class, 'createMomoPayment'])->name('createMomoPayment');


//tour booked
Route::get('/tour-booked', [TourBookedController::class, 'index'])->name('tour-booked');
Route::post('/cancel-booking', [TourBookedController::class, 'cancelBooking'])->name('cancel-booking');

//My tour
Route::get('/my-tours', [MyTourController::class, 'index'])->name('my-tours');

//admin
Route::prefix('admin')->group(function () {
    Route::get('/login', [LoginAdminController::class, 'index'])->name('admin.login');
    Route::post('/login-account', [LoginAdminController::class, 'loginAdmin'])->name('admin.login-account');
    Route::get('/tours', [ToursManagementController::class, 'index'])->name('admin.tours');
    Route::get('/tour-edit', [ToursManagementController::class, 'getTourEdit'])->name('admin.tour-edit');
    Route::post('/edit-tour', [ToursManagementController::class, 'updateTour'])->name('admin.edit-tour');
    Route::post('/delete-tour', [ToursManagementController::class, 'deleteTour'])->name('admin.delete-tour');


});

