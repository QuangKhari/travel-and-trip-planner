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
use App\Http\Controllers\clients\ForgotPasswordController;
use App\Http\Controllers\clients\EmailVerificationController;
use App\Http\Controllers\clients\EmailChangeController;
use App\Http\Controllers\clients\ExploreController;
use App\Http\Controllers\clients\ExploreContentController;
use App\Http\Controllers\clients\MarkController;
use App\Http\Controllers\clients\GuidePagesController;
use App\Http\Controllers\clients\SeasonPagesController;
use App\Http\Controllers\clients\SiteSearchController;
use App\Http\Controllers\clients\SeoController;
use App\Http\Controllers\clients\DataSourcesController;
use App\Http\Controllers\clients\JournalController;
use App\Http\Controllers\clients\AskPageController;
use App\Http\Controllers\clients\PlannerController;


use App\Http\Controllers\admin\LoginAdminController;
use App\Http\Controllers\admin\ToursManagementController;
use App\Http\Controllers\admin\UserManagementController;
use App\Http\Controllers\admin\DashboardController;
use App\Http\Controllers\admin\BookingManagementController;
use App\Http\Controllers\admin\ReviewManagementController;
use App\Http\Controllers\admin\AdminManagementController;
use App\Http\Controllers\admin\PromotionManagementController;
use App\Http\Controllers\admin\ItineraryItemsController;
use App\Http\Controllers\admin\DestinationManagementController;
use App\Http\Controllers\admin\InspirationManagementController;
use App\Http\Controllers\admin\GuideCategoryController;
use App\Http\Controllers\admin\ContactInboxController;
use App\Http\Controllers\admin\ChatLogController;
use App\Http\Controllers\admin\ContentOverviewController;
use App\Http\Controllers\admin\BlogAdminController;
use App\Http\Controllers\admin\ContentReviewController;




Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/about', [AboutController::class, 'index'])->name('about');
Route::get('/contact', [ContactController::class, 'index'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1,contact')->name('contact.send');
// Lớp Khám phá: URL không dấu theo tài liệu mục 3. Tên route cũ (destination, destination.show) giữ nguyên.
Route::get('/diem-den', [DestinationController::class, 'index'])->name('destination');
Route::get('/diem-den/tat-ca', [ExploreContentController::class, 'destinations'])->middleware('throttle:60,1,destination-list')->name('destinations.list');
Route::get('/diem-den/{slug}', [\App\Http\Controllers\clients\DestinationDetailController::class, 'show'])->where('slug', '[a-z0-9-]+')->middleware('throttle:60,1,destination-detail')->name('destination.show');
// Đường dẫn cũ /destination chuyển vĩnh viễn sang /diem-den (một URL chuẩn cho mỗi trang)
Route::permanentRedirect('/destination', '/diem-den');
Route::get('/destination/{slug}', fn(string $slug) => redirect()->route('destination.show', ['slug' => $slug], 301))->where('slug', '[a-z0-9-]+');

Route::middleware('throttle:60,1,guide-pages')->group(function () {
    Route::get('/am-thuc', [ExploreContentController::class, 'foods'])->name('foods.index');
    Route::get('/am-thuc/{slug}', [ExploreContentController::class, 'food'])->where('slug', '[a-z0-9-]+')->name('foods.show');
    Route::get('/trai-nghiem', [\App\Http\Controllers\clients\ExperienceController::class, 'index'])->name('experiences.index');
    Route::get('/trai-nghiem/{slug}', [\App\Http\Controllers\clients\ExperienceController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('experiences.show');
    Route::get('/phai-thu', [\App\Http\Controllers\clients\MustDoController::class, 'index'])->name('mustdo.index');
    Route::get('/le-hoi', [ExploreContentController::class, 'events'])->name('events.index');
    Route::get('/le-hoi/lich', [ExploreContentController::class, 'eventCalendar'])->name('events.calendar');
    Route::get('/le-hoi/{slug}', [ExploreContentController::class, 'event'])->where('slug', '[a-z0-9-]+')->name('events.show');
    Route::get('/lich-trinh', [ExploreContentController::class, 'itineraries'])->name('itineraries.index');
    Route::get('/lich-trinh/{slug}', [ExploreContentController::class, 'itinerary'])->where('slug', '[a-z0-9-]+')->name('itineraries.show');

    // C1: cẩm nang
    Route::get('/cam-nang', [GuidePagesController::class, 'index'])->name('guides.index');
    Route::get('/cam-nang/{category}', [GuidePagesController::class, 'category'])->where('category', '[a-z0-9-]+')->name('guides.category');
    Route::get('/cam-nang/{category}/{slug}', [GuidePagesController::class, 'show'])->where(['category' => '[a-z0-9-]+', 'slug' => '[a-z0-9-]+'])->name('guides.show');
    // C2: đang vào mùa, nên đi tháng nào
    Route::get('/dang-vao-mua', [SeasonPagesController::class, 'now'])->name('seasons.now');
    Route::get('/nen-di-thang-nao/{thang?}', [SeasonPagesController::class, 'matrix'])->whereNumber('thang')->name('seasons.matrix');
    // C3, C5
    Route::get('/tim-kiem', [SiteSearchController::class, 'index'])->name('site.search');
    Route::get('/nguon-du-lieu', [DataSourcesController::class, 'index'])->name('data.sources');
});
// C4: sitemap.xml và robots.txt động (xóa public/robots.txt vì tệp tĩnh sẽ che route này)
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->middleware('throttle:30,1,sitemap')->name('sitemap');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
// Lưu / đã đến / đã thử / đã dự và đồng bộ khay chuyến đi (cần đăng nhập; fetch nhận JSON 401 khi chưa đăng nhập)
Route::middleware(['checkLoginClient', 'checkUserBlocked', 'throttle:60,1,marks'])->group(function () {
    Route::post('/danh-dau', [MarkController::class, 'toggle'])->name('marks.toggle');
    Route::post('/khay-chuyen-di/dong-bo', [MarkController::class, 'syncTray'])->name('trip-tray.sync');
});

//hỏi đáp du lịch (trang công khai; gửi câu hỏi cần đăng nhập và có giới hạn lượt)
Route::get('/hoi-dap', [AskPageController::class, 'index'])->middleware('throttle:60,1,ask-page')->name('ask.index');
Route::post('/hoi-dap/hoi', [AskPageController::class, 'ask'])->middleware(['checkLoginClient', 'checkUserBlocked', 'throttle:10,1,ask-send'])->name('ask.send');

// Bản chỉ đọc của chuyến đi được chia sẻ (công khai, không đưa vào chỉ mục)
Route::get('/chuyen-di/{token}', [PlannerController::class, 'shared'])->where('token', '[A-Za-z0-9]{32}')->middleware('throttle:60,1,trip-share')->name('planner.shared');

Route::get('/explore', [ExploreController::class, 'index'])->middleware('throttle:60,1,explore')->name('explore');
Route::get('/travel-guides', [TravelGuidesController::class, 'index'])->name('team');
Route::get('/tour-detail/{id}', [TourDetailController::class, 'index'])->whereNumber('id')->name('tour-detail');
Route::get('/blogs', [BlogController::class, 'index'])->name('blogs');
Route::get('/blog-detail/{slug?}', [BlogDetailController::class, 'index'])->name('blog-detail');
Route::get('/search', [SearchController::class, 'index'])->middleware('throttle:60,1,search')->name('search');
Route::get('/search-voice-text', [SearchController::class, 'searchTours'])->middleware('throttle:30,1,search-voice')->name('search-voice-text');

//Đăng nhập, đăng ký, đăng xuất
Route::get('/login', [LoginController::class, 'index'])->name('login');
Route::get('/register', [LoginController::class, 'registerPage'])->name('register.form');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1,user-login')->name('user-login');
Route::post('/register', [LoginController::class, 'register'])->middleware('throttle:10,1,register')->name('register');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

//xác thực email
Route::get('/xac-thuc-email/{token}', [EmailVerificationController::class, 'verify'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('email.verify');

Route::post('/xac-thuc-email/gui-lai', [EmailVerificationController::class, 'resend'])
    ->middleware('throttle:3,10,email-verification')
    ->name('email.verification.resend');

Route::get('/xac-thuc-email-moi/{token}', [EmailChangeController::class, 'verify'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('email.change.verify');

//quên mật khẩu 
Route::get('/quen-mat-khau', [ForgotPasswordController::class, 'index'])->name('password.request');
Route::post('/quen-mat-khau', [ForgotPasswordController::class, 'sendLink'])->middleware('throttle:5,1,password-email')->name('password.email');
Route::get('/dat-lai-mat-khau/{token}', [ForgotPasswordController::class, 'showResetForm'])->name('password.reset.form');
Route::post('/doi-mat-khau', [ForgotPasswordController::class, 'reset'])->middleware('throttle:10,1,password-reset')->name('password.update');


//tours, filter tours, tour detail
Route::get('/tours', [ToursController::class, 'index'])->name('tours');
Route::get('/filter-tours', [ToursController::class, 'filterTours'])
    ->middleware('throttle:60,1,filter-tours')
    ->name('filter-tours');




// ===== Khu vực cần đăng nhập và tài khoản không bị khóa =====
Route::middleware(['checkLoginClient', 'checkUserBlocked'])->group(function () {
    // hồ sơ
    Route::get('/user-profile', [UserProfileController::class, 'index'])->name('user-profile');
    Route::post('/user-profile', [UserProfileController::class, 'update'])->name('update-user-profile');
    Route::post('/change-password-profile', [UserProfileController::class, 'changePassword'])->name('change-password');
    Route::post('/change-avatar-profile', [UserProfileController::class, 'changeAvatar'])->name('change-avatar');

    Route::post('/xac-thuc-email-moi/gui-lai', [EmailChangeController::class, 'resend'])
        ->middleware('throttle:3,10,email-change-verification')
        ->name('email.change.resend');

    // đặt tour
    // GET để F5 hoặc mở link trực tiếp không bị 405 
    Route::match(['get', 'post'], '/booking/{id?}', [BookingController::class, 'index'])->whereNumber('id')->name('booking');
    Route::post('/create-booking', [BookingController::class, 'createBooking'])->middleware('throttle:10,1,create-booking')->name('create-booking');
    Route::post('/apply-coupon', [BookingController::class, 'applyCoupon'])->middleware('throttle:20,1,apply-coupon')->name('apply-coupon');
    Route::post('/checkBooking', [BookingController::class, 'checkBooking'])->name('checkBooking');

    // đơn đã đặt, đánh giá
    Route::get('/tour-booked', [TourBookedController::class, 'index'])->name('tour-booked');
    Route::post('/cancel-booking', [TourBookedController::class, 'cancelBooking'])->name('cancel-booking');
    Route::post('/reviews', [TourDetailController::class, 'reviews'])->middleware('throttle:10,1,reviews')->name('reviews');
    Route::get('/my-tours', [MyTourController::class, 'index'])->name('my-tours');
    Route::get('/so-tay-du-hanh', [JournalController::class, 'index'])->name('journal');

    // Trip Planner (tuần 5-7)
    Route::get('/len-ke-hoach', [PlannerController::class, 'index'])->name('planner');
    Route::post('/len-ke-hoach', [PlannerController::class, 'store'])->middleware('throttle:20,1,planner-store')->name('planner.store');
    Route::get('/len-ke-hoach/{id}', [PlannerController::class, 'edit'])->whereNumber('id')->name('planner.edit');
    Route::post('/len-ke-hoach/{id}/luu', [PlannerController::class, 'save'])->whereNumber('id')->middleware('throttle:60,1,planner-save')->name('planner.save');
    Route::post('/len-ke-hoach/{id}/chia-se', [PlannerController::class, 'share'])->whereNumber('id')->middleware('throttle:30,1,planner-share')->name('planner.share');
    Route::post('/len-ke-hoach/{id}/xoa', [PlannerController::class, 'destroy'])->whereNumber('id')->middleware('throttle:20,1,planner-del')->name('planner.destroy');
});

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
        Route::post('/update-basic-tour', [ToursManagementController::class, 'updateBasicTour'])->name('admin.update-basic-tour');
        Route::post('/delete-tour', [ToursManagementController::class, 'deleteTour'])->name('admin.delete-tour');
        Route::post('/toggle-tour', [ToursManagementController::class, 'toggleTour'])->name('admin.toggle-tour');
        Route::post('/add-temp-images', [ToursManagementController::class, 'uploadTempImagesTours'])->name('admin.add-temp-images');
        Route::post('/upload-tour-image', [ToursManagementController::class, 'uploadTourImage'])->name('admin.upload-tour-image');
        Route::post('/add-timeline', [ToursManagementController::class, 'addTimeline'])->name('admin.add-timeline');

        //Management User
        Route::get('/users', [UserManagementController::class, 'index'])->name('admin.users');
        Route::post('/active-user', [UserManagementController::class, 'activeUser'])->name('admin.active-user');
        Route::post('/status-user', [UserManagementController::class, 'changeStatus'])->name('admin.status-user');

        //Management Booking - thêm quyền
        Route::post('/finish-booking', [BookingManagementController::class, 'finishBooking'])->name('admin.finish-booking');
        Route::post('/admin-cancel-booking', [BookingManagementController::class, 'cancelBooking'])->name('admin.cancel-booking');
        Route::post('/received-money', [BookingManagementController::class, 'receiviedMoney'])->name('admin.received');
        Route::post('/confirm-payment', [BookingManagementController::class, 'confirmPayment'])->name('admin.confirm-payment');
        Route::post('/send-pdf', [BookingManagementController::class, 'sendPdf'])->middleware('throttle:10,1,send-pdf')->name('admin.send.pdf');

        //Promotion
        Route::get('/promotion', [PromotionManagementController::class, 'index'])->name('admin.promotion');
        Route::post('/add-promotion', [PromotionManagementController::class, 'addPromotion'])->name('admin.add-promotion');
        Route::get('/promotion-edit/{id}', [PromotionManagementController::class, 'getPromotionEdit'])->name('admin.promotion-edit');
        Route::post('/edit-promotion', [PromotionManagementController::class, 'editPromotion'])->name('admin.edit-promotion');
        Route::post('/delete-promotion', [PromotionManagementController::class, 'deletePromotion'])->name('admin.delete-promotion');

        // Duyệt & quản lý nội dung Khám phá
        Route::get('/content-overview', [ContentOverviewController::class, 'index'])->name('admin.content.overview');
        Route::get('/content-lookup', [ItineraryItemsController::class, 'lookup'])->name('admin.content.lookup');
        Route::post('/content/rag-rebuild', [ContentReviewController::class, 'rebuildRag'])->name('admin.content.rag');
        Route::get('/content/{kind?}', [ContentReviewController::class, 'index'])->name('admin.content');
        Route::get('/content/{kind}/create', [ContentReviewController::class, 'create'])->name('admin.content.create');
        Route::post('/content/{kind}/bulk', [ContentReviewController::class, 'bulk'])->name('admin.content.bulk');
        Route::post('/content/{kind}', [ContentReviewController::class, 'store'])->name('admin.content.store');
        Route::get('/content/{kind}/{id}/edit', [ContentReviewController::class, 'edit'])->whereNumber('id')->name('admin.content.edit');
        Route::get('/content/{kind}/{id}/preview', [ContentReviewController::class, 'preview'])->whereNumber('id')->name('admin.content.preview');
        Route::post('/content/{kind}/{id}/status', [ContentReviewController::class, 'status'])->whereNumber('id')->name('admin.content.status');
        Route::post('/content/{kind}/{id}/reject', [ContentReviewController::class, 'reject'])->whereNumber('id')->name('admin.content.reject');
        Route::post('/content/{kind}/{id}/delete', [ContentReviewController::class, 'destroy'])->whereNumber('id')->name('admin.content.destroy');
        Route::post('/content/{kind}/{id}', [ContentReviewController::class, 'update'])->whereNumber('id')->name('admin.content.update');

        Route::get('/itinerary/{id}/items', [ItineraryItemsController::class, 'edit'])->whereNumber('id')->name('admin.itinerary.items');
        Route::post('/itinerary/{id}/items', [ItineraryItemsController::class, 'save'])->whereNumber('id')->name('admin.itinerary.items.save');

        Route::get('/destinations', [DestinationManagementController::class, 'index'])->name('admin.destinations');
        Route::get('/destinations/create', [DestinationManagementController::class, 'create'])->name('admin.destinations.create');
        Route::post('/destinations', [DestinationManagementController::class, 'store'])->name('admin.destinations.store');
        Route::get('/destinations/{id}/edit', [DestinationManagementController::class, 'edit'])->whereNumber('id')->name('admin.destinations.edit');
        Route::post('/destinations/{id}', [DestinationManagementController::class, 'update'])->whereNumber('id')->name('admin.destinations.update');
        Route::post('/destinations/{id}/months', [DestinationManagementController::class, 'months'])->whereNumber('id')->name('admin.destinations.months');
        Route::post('/destinations/{id}/inspirations', [DestinationManagementController::class, 'inspirations'])->whereNumber('id')->name('admin.destinations.inspirations');
        Route::post('/destinations/{id}/delete', [DestinationManagementController::class, 'destroy'])->whereNumber('id')->name('admin.destinations.destroy');

        Route::get('/inspirations', [InspirationManagementController::class, 'index'])->name('admin.inspirations');
        Route::post('/inspirations', [InspirationManagementController::class, 'store'])->name('admin.inspirations.store');
        Route::post('/inspirations/{id}', [InspirationManagementController::class, 'update'])->whereNumber('id')->name('admin.inspirations.update');
        Route::post('/inspirations/{id}/delete', [InspirationManagementController::class, 'destroy'])->whereNumber('id')->name('admin.inspirations.destroy');

        Route::get('/guide-categories', [GuideCategoryController::class, 'index'])->name('admin.guide-categories');
        Route::post('/guide-categories', [GuideCategoryController::class, 'store'])->name('admin.guide-categories.store');
        Route::post('/guide-categories/{id}', [GuideCategoryController::class, 'update'])->whereNumber('id')->name('admin.guide-categories.update');
        Route::post('/guide-categories/{id}/delete', [GuideCategoryController::class, 'destroy'])->whereNumber('id')->name('admin.guide-categories.destroy');

        Route::get('/contact-inbox', [ContactInboxController::class, 'index'])->name('admin.contact');
        Route::post('/contact-inbox/{id}', [ContactInboxController::class, 'update'])->whereNumber('id')->name('admin.contact.update');
        Route::post('/contact-inbox/{id}/delete', [ContactInboxController::class, 'destroy'])->whereNumber('id')->name('admin.contact.destroy');

        Route::get('/chat-logs', [ChatLogController::class, 'index'])->name('admin.chat');
        Route::get('/chat-logs/export', [ChatLogController::class, 'export'])->name('admin.chat.export');

        Route::get('/blog-posts', [BlogAdminController::class, 'index'])->name('admin.blog');
        Route::get('/blog-posts/create', [BlogAdminController::class, 'create'])->name('admin.blog.create');
        Route::post('/blog-posts/import', [BlogAdminController::class, 'importConfig'])->name('admin.blog.import');
        Route::post('/blog-posts', [BlogAdminController::class, 'store'])->name('admin.blog.store');
        Route::get('/blog-posts/{id}/edit', [BlogAdminController::class, 'edit'])->whereNumber('id')->name('admin.blog.edit');
        Route::post('/blog-posts/{id}', [BlogAdminController::class, 'update'])->whereNumber('id')->name('admin.blog.update');
        Route::post('/blog-posts/{id}/delete', [BlogAdminController::class, 'destroy'])->whereNumber('id')->name('admin.blog.destroy');

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

if (app()->environment('local')) {
    Route::view('/ui-kit', 'clients.ui-kit')->name('ui-kit');
}
