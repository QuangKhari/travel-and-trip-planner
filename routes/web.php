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
use App\Http\Controllers\admin\UserManagementController;
use App\Http\Controllers\admin\DashboardController;
use App\Http\Controllers\admin\BookingManagementController;
use App\Http\Controllers\admin\ReviewManagementController;
use App\Http\Controllers\admin\AdminManagementController;
use App\Http\Controllers\admin\PromotionManagementController;
use App\Http\Controllers\clients\ForgotPasswordController;




Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/about', [AboutController::class, 'index'])->name('about');
Route::get('/contact', [ContactController::class, 'index'])->name('contact');
Route::get('/destination', [DestinationController::class, 'index'])->name('destination');
Route::get('/travel-guides', [TravelGuidesController::class, 'index'])->name('team');
Route::get('/tour-detail/{id}', [TourDetailController::class, 'index'])->whereNumber('id')->name('tour-detail');
Route::get('/blogs', [BlogController::class, 'index'])->name('blogs');
Route::get('/blog-detail', [BlogDetailController::class, 'index'])->name('blog-detail');
Route::get('/search', [SearchController::class, 'index'])->name('search');
Route::get('/search-voice-text', [SearchController::class, 'searchTours'])->name('search-voice-text');

//Đăng nhập, đăng ký, đăng xuất
Route::get('/login', [LoginController::class, 'index'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1,user-login')->name('user-login');
Route::post('/register', [LoginController::class, 'register'])->middleware('throttle:10,1,register')->name('register');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
//quên mật khẩu 
Route::get('/quen-mat-khau', [ForgotPasswordController::class, 'index'])->name('password.request');
Route::post('/xac-thuc-tai-khoan', [ForgotPasswordController::class, 'verifyAccount'])->name('password.verify');
Route::post('/doi-mat-khau', [ForgotPasswordController::class, 'reset'])->name('password.update');


//tours, filter tours, tour detail
Route::get('/tours', [ToursController::class, 'index'])->name('tours');
Route::get('/filter-tours', [ToursController::class, 'filterTours'])->name('filter-tours');




// ===== Khu vực cần đăng nhập và tài khoản không bị khóa (L-A-06, L-A-07) =====
Route::middleware(['checkLoginClient', 'checkUserBlocked'])->group(function () {
    // hồ sơ
    Route::get('/user-profile', [UserProfileController::class, 'index'])->name('user-profile');
    Route::post('/user-profile', [UserProfileController::class, 'update'])->name('update-user-profile');
    Route::post('/change-password-profile', [UserProfileController::class, 'changePassword'])->name('change-password');
    Route::post('/change-avatar-profile', [UserProfileController::class, 'changeAvatar'])->name('change-avatar');

    // đặt tour
    Route::post('/booking/{id?}', [BookingController::class, 'index'])->name('booking');
    Route::post('/create-booking', [BookingController::class, 'createBooking'])->name('create-booking');
    Route::post('/apply-coupon', [BookingController::class, 'applyCoupon'])->name('apply-coupon');
    Route::post('/checkBooking', [BookingController::class, 'checkBooking'])->name('checkBooking');

    // đơn đã đặt, đánh giá
    Route::get('/tour-booked', [TourBookedController::class, 'index'])->name('tour-booked');
    Route::post('/cancel-booking', [TourBookedController::class, 'cancelBooking'])->name('cancel-booking');
    Route::post('/reviews', [TourDetailController::class, 'reviews'])->name('reviews');
    Route::get('/my-tours', [MyTourController::class, 'index'])->name('my-tours');
});

// ===== TẠM TẮT (L-B-06, L-B-14, L-B-15) – thay bằng VNPay ở Tuần 3–4 =====
// /payment-confirm: đã xóa hẳn (không có view nào gọi).
// Hai route dưới giữ lại tên để view không báo "Route not defined", nhưng luôn trả 410.
Route::match(['get', 'post'], '/create-momo-payment', fn() => response()->json([
    'success' => false,
    'message' => 'Thanh toán MoMo đã ngừng hỗ trợ.',
], 410))->name('createMomoPayment');

Route::post('/upload-transfer-proof', fn() => response()->json([
    'success' => false,
    'message' => 'Chức năng tải biên lai đã tạm ngừng.',
], 410))->name('booking.upload-transfer-proof');

//admin
Route::prefix('admin')->group(function () {
    //Đăng nhập, đăng xuất
    Route::get('/login', [LoginAdminController::class, 'index'])->name('admin.login');
    Route::post('/login-account', [LoginAdminController::class, 'loginAdmin'])->middleware('throttle:10,1,admin-login')->name('admin.login-account');
    Route::post('/logout', [LoginAdminController::class, 'logout'])->name('admin.logout');

    // Tất cả role đều vào được (admin, manager, staff)
    Route::middleware(['checkAdminRole:admin,manager,staff'])->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('admin.dashboard');

        //Management Booking
        Route::get('/booking', [BookingManagementController::class, 'index'])->name('admin.booking');
        Route::get('/booking-detail/{id?}', [BookingManagementController::class, 'showDetail'])->name('admin.booking-detail');
        Route::post('/confirm-booking', [BookingManagementController::class, 'confirmBooking'])->name('admin.confirm-booking');
        Route::get('/transfer-proof/{id}', [BookingManagementController::class, 'transferProof'])->name('admin.transfer-proof');

        //Reviews
        Route::get('/reviews', [ReviewManagementController::class, 'index'])->name('admin.reviews');
        Route::get('/reviews/{id}', [ReviewManagementController::class, 'show'])->name('admin.reviews.show');
    });

    // Chỉ admin và manager
    Route::middleware(['checkAdminRole:admin,manager'])->group(function () {
        //Management Tours
        Route::get('/tours', [ToursManagementController::class, 'index'])->name('admin.tours');
        Route::get('/tour-edit', [ToursManagementController::class, 'getTourEdit'])->name('admin.tour-edit');
        Route::post('/edit-tour', [ToursManagementController::class, 'updateTour'])->name('admin.edit-tour');
        Route::get('/page-add-tours', [ToursManagementController::class, 'pageAddTours'])->name('admin.page-add-tours');
        Route::post('/add-tours', [ToursManagementController::class, 'addTours'])->name('admin.add-tours');
        Route::post('/delete-tour', [ToursManagementController::class, 'deleteTour'])->name('admin.delete-tour');
        Route::post('/add-temp-images', [ToursManagementController::class, 'uploadTempImagesTours'])->name('admin.add-temp-images');
        Route::post('/add-images-tours', [ToursManagementController::class, 'addImagesTours'])->name('admin.add-images-tours');
        Route::post('/add-timeline', [ToursManagementController::class, 'addTimeline'])->name('admin.add-timeline');

        //Management User
        Route::get('/users', [UserManagementController::class, 'index'])->name('admin.users');
        Route::post('/active-user', [UserManagementController::class, 'activeUser'])->name('admin.active-user');
        Route::post('/status-user', [UserManagementController::class, 'changeStatus'])->name('admin.status-user');

        //Management Booking - thêm quyền
        Route::post('/finish-booking', [BookingManagementController::class, 'finishBooking'])->name('admin.finish-booking');
        Route::post('/received-money', [BookingManagementController::class, 'receiviedMoney'])->name('admin.received');
        Route::post('/confirm-payment', [BookingManagementController::class, 'confirmPayment'])->name('admin.confirm-payment');
        Route::post('/admin/send-pdf', [BookingManagementController::class, 'sendPdf'])->name('admin.send.pdf');

        //Promotion
        Route::get('/promotion', [PromotionManagementController::class, 'index'])->name('admin.promotion');
        Route::post('/add-promotion', [PromotionManagementController::class, 'addPromotion'])->name('admin.add-promotion');
        Route::get('/promotion-edit/{id}', [PromotionManagementController::class, 'getPromotionEdit'])->name('admin.promotion-edit');
        Route::post('/edit-promotion', [PromotionManagementController::class, 'editPromotion'])->name('admin.edit-promotion');
        Route::post('/delete-promotion', [PromotionManagementController::class, 'deletePromotion'])->name('admin.delete-promotion');

        //Reviews - xóa
        Route::delete('/reviews/{id}', [ReviewManagementController::class, 'destroy'])->name('admin.reviews.destroy');

        //Management Staff - manager thêm/xóa staff
        Route::get('/admin', [AdminManagementController::class, 'index'])->name('admin.admin');
        Route::post('/add-staff', [AdminManagementController::class, 'addStaff'])->name('admin.add-staff');
        Route::post('/delete-staff', [AdminManagementController::class, 'deleteStaff'])->name('admin.delete-staff');
    });

    // Chỉ admin
    Route::middleware(['checkAdminRole:admin'])->group(function () {
        //Management Admin
        Route::post('/update-admin', [AdminManagementController::class, 'updateAdmin'])->name('admin.update-admin');
        Route::post('/update-avatar', [AdminManagementController::class, 'updateAvatar'])->name('admin.update-avatar');

        //Management Manager - admin thêm/xóa manager
        Route::post('/add-manager', [AdminManagementController::class, 'addManager'])->name('admin.add-manager');
        Route::post('/delete-manager', [AdminManagementController::class, 'deleteManager'])->name('admin.delete-manager');
    });
});
