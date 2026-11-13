<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\clients\HomeController;
use App\Http\Controllers\clients\AboutController;
use App\Http\Controllers\clients\ContactController;
use App\Http\Controllers\clients\BookingController;
use App\Http\Controllers\clients\DestinationController;
use App\Http\Controllers\clients\DestinationMonthController;
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
use App\Http\Controllers\clients\PlannerAiController;


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
use App\Http\Controllers\admin\ContentQualityController;
use App\Http\Controllers\admin\BlogAdminController;
use App\Http\Controllers\admin\ContentReviewController;
use App\Http\Controllers\admin\TripAdminController;
use App\Http\Controllers\admin\ContentRequestAdminController;
use App\Http\Controllers\admin\SystemController;
use App\Http\Controllers\admin\RefundManagementController;
use App\Http\Controllers\admin\ReconciliationController;
use App\Http\Controllers\admin\TourIncidentController;
use App\Http\Controllers\admin\WaitlistAdminController;
use App\Http\Controllers\admin\BookingAdjustmentAdminController;
use App\Http\Controllers\admin\FeasibilityController;
use App\Http\Controllers\admin\ProfitController;
use App\Http\Controllers\admin\SupplierController;
use App\Http\Controllers\admin\ComplaintAdminController;
use App\Http\Controllers\clients\WaitlistController;
use App\Http\Controllers\clients\BookingAdjustController;
use App\Http\Controllers\clients\ComplaintController;
use App\Http\Controllers\admin\DepartureManagementController;
use App\Http\Controllers\admin\DepartureGroupingController;
use App\Http\Controllers\admin\DepartureRescueController;
use App\Http\Controllers\clients\DepartureRescueResponseController;
use App\Http\Controllers\admin\AuditLogController;
use App\Http\Controllers\admin\SupportTicketAdminController;
use App\Http\Controllers\admin\PaymentAdminController;
use App\Http\Controllers\clients\SupportTicketController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\clients\PaymentController;
use App\Http\Controllers\clients\QuoteController;
use App\Http\Controllers\clients\CompareController;
use App\Http\Controllers\clients\CommunityController;
use App\Http\Controllers\clients\TravelController;





Route::get('/', [HomeController::class, 'index'])->name('home');
// Kiểm tra sức khỏe hệ thống cho giám sát / Docker healthcheck (PF-08): DB, cache, hàng đợi, đĩa
Route::get('/health', HealthController::class)->middleware('throttle:60,1,health')->name('health');
// Thanh toán trực tuyến: cổng gọi máy chủ (VNPay / MoMo IPN, webhook SePay — không phiên, không CSRF) và trang khách quay về
Route::match(['get', 'post'], '/thanh-toan/thong-bao/{method}', [PaymentController::class, 'notify'])->whereIn('method', ['vnpay', 'momo', 'qr'])->middleware('throttle:240,1,pay-notify')->name('payment.notify');
Route::get('/thanh-toan/ket-qua/{method}', [PaymentController::class, 'return'])->whereIn('method', ['vnpay', 'momo'])->middleware('throttle:30,1,pay-return')->name('payment.return');
Route::get('/about', [AboutController::class, 'index'])->name('about');
Route::get('/contact', [ContactController::class, 'index'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1,contact')->name('contact.send');
// Lớp Khám phá: URL không dấu theo tài liệu mục 3. Tên route cũ (destination, destination.show) giữ nguyên.
Route::get('/diem-den', [DestinationController::class, 'index'])->name('destination');
Route::get('/diem-den/tat-ca', [ExploreContentController::class, 'destinations'])->middleware('throttle:60,1,destination-list')->name('destinations.list');
Route::get('/diem-den/{slug}', [\App\Http\Controllers\clients\DestinationDetailController::class, 'show'])->where('slug', '[a-z0-9-]+')->middleware('throttle:60,1,destination-detail')->name('destination.show');
Route::get('/diem-den/{slug}/thang-{n}', [DestinationMonthController::class, 'show'])->where('slug', '[a-z0-9-]+')->where('n', '([1-9]|1[0-2])')->middleware('throttle:60,1,destination-month')->name('destination.month');
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
Route::get('/da-luu', [\App\Http\Controllers\clients\SavedCollectionController::class, 'index'])
    ->middleware(['checkLoginClient', 'checkUserBlocked', 'throttle:60,1'])
    ->name('saved.collection');

Route::middleware(['checkLoginClient', 'checkUserBlocked', 'throttle:60,1,marks'])->group(function () {
    Route::post('/danh-dau', [MarkController::class, 'toggle'])->name('marks.toggle');
    Route::post('/khay-chuyen-di/dong-bo', [MarkController::class, 'syncTray'])->name('trip-tray.sync');
});

//hỏi đáp du lịch (trang công khai; gửi câu hỏi cần đăng nhập và có giới hạn lượt)
Route::get('/hoi-dap', [AskPageController::class, 'index'])->middleware('throttle:60,1,ask-page')->name('ask.index');
Route::post('/hoi-dap/hoi', [AskPageController::class, 'ask'])->middleware(['checkLoginClient', 'checkUserBlocked', 'throttle:10,1,ask-send'])->name('ask.send');

// Bản chỉ đọc của chuyến đi được chia sẻ (công khai, không đưa vào chỉ mục)
Route::get('/chuyen-di/{token}', [PlannerController::class, 'shared'])->where('token', '[A-Za-z0-9]{32}')->middleware('throttle:60,1,trip-share')->name('planner.shared');

// Cộng đồng hành trình (T-24…T-35): xem công khai; thích, dùng lại, công khai chuyến của mình cần đăng nhập
Route::get('/cong-dong', [CommunityController::class, 'index'])->middleware('throttle:60,1,community')->name('community.index');
Route::get('/cong-dong/{id}', [CommunityController::class, 'show'])->whereNumber('id')->middleware('throttle:60,1,community')->name('community.show');
Route::middleware(['checkLoginClient', 'checkUserBlocked'])->group(function () {
    Route::post('/cong-dong/{id}/thich', [CommunityController::class, 'like'])->whereNumber('id')->middleware('throttle:30,1,community-like')->name('community.like');
    Route::post('/cong-dong/{id}/dung-lai', [CommunityController::class, 'useTrip'])->whereNumber('id')->middleware('throttle:10,1,community-use')->name('community.use');
    Route::post('/len-ke-hoach/{id}/cong-dong', [CommunityController::class, 'publish'])->whereNumber('id')->middleware('throttle:20,1,community-pub')->name('community.publish');
});

Route::get('/explore', [ExploreController::class, 'index'])->middleware('throttle:60,1,explore')->name('explore');
Route::get('/travel-guides', [TravelGuidesController::class, 'index'])->name('team');
Route::get('/dat-tour/bao-gia', [QuoteController::class, 'quote'])->middleware('throttle:60,1,quote')->name('booking.quote');
Route::get('/tour/{id}/cho-trong', [QuoteController::class, 'seats'])->whereNumber('id')->middleware('throttle:30,1,seats')->name('tour.seats');
Route::get('/so-sanh-tour', [CompareController::class, 'index'])->middleware('throttle:30,1,compare')->name('tours.compare');
Route::get('/tour-detail/{id}', [TourDetailController::class, 'index'])->whereNumber('id')->name('tour-detail');
Route::get('/tour-detail/{id}/danh-gia', [TourDetailController::class, 'moreReviews'])->whereNumber('id')->middleware('throttle:60,1,tour-reviews')->name('tour-reviews');
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
    Route::get('/thanh-toan/{method}/{bookingId}', [PaymentController::class, 'pay'])->whereIn('method', ['vnpay', 'momo', 'qr'])->whereNumber('bookingId')->middleware('throttle:10,1,pay-start')->name('payment.pay');
    Route::post('/thanh-toan/{bookingId}/kiem-tra', [PaymentController::class, 'check'])->whereNumber('bookingId')->middleware('throttle:6,1,pay-check')->name('payment.check');
    Route::get('/thanh-toan/{bookingId}/trang-thai', [PaymentController::class, 'status'])->whereNumber('bookingId')->middleware('throttle:40,1,pay-status')->name('payment.status');
    Route::post('/create-booking', [BookingController::class, 'createBooking'])->middleware('throttle:10,1,create-booking')->name('create-booking');
    Route::post('/apply-coupon', [BookingController::class, 'applyCoupon'])->middleware('throttle:20,1,apply-coupon')->name('apply-coupon');
    Route::post('/checkBooking', [BookingController::class, 'checkBooking'])->name('checkBooking');

    // đơn đã đặt, đánh giá
    Route::get('/tour-booked', [TourBookedController::class, 'index'])->name('tour-booked');
    Route::post('/cancel-booking', [TourBookedController::class, 'cancelBooking'])->name('cancel-booking');
    Route::post('/reviews', [TourDetailController::class, 'reviews'])->middleware('throttle:10,1,reviews')->name('reviews');
    Route::get('/my-tours', [MyTourController::class, 'index'])->name('my-tours');
    Route::get('/dieu-chinh-chuyen-di', [DepartureRescueResponseController::class, 'index'])->name('departure.rescue.responses');
    Route::post('/dieu-chinh-chuyen-di/{planId}/{bookingId}', [DepartureRescueResponseController::class, 'respond'])->whereNumber(['planId','bookingId'])->middleware('throttle:10,1,rescue-response')->name('departure.rescue.respond');
    Route::get('/so-tay-du-hanh', [JournalController::class, 'index'])->name('journal');

    // Trip Planner (tuần 5-7)
    Route::get('/len-ke-hoach', [PlannerController::class, 'index'])->name('planner');
    Route::get('/len-ke-hoach/de-xuat', [PlannerController::class, 'suggest'])->name('planner.suggest');
    Route::get('/len-ke-hoach/chon-diem-den', [PlannerController::class, 'discover'])->middleware('throttle:30,1,planner-discover')->name('planner.discover');
    Route::get('/len-ke-hoach/tim-noi', [PlannerController::class, 'placeSearch'])->middleware('throttle:60,1,planner-place')->name('planner.placeSearch');
    Route::get('/len-ke-hoach/de-xuat/xem-truoc', [PlannerController::class, 'preview'])->middleware('throttle:30,1,planner-preview')->name('planner.preview');
    Route::post('/len-ke-hoach/tao-tu-dong', [PlannerController::class, 'generate'])->middleware('throttle:10,1,planner-generate')->name('planner.generate');
    // La bàn (AI): F1 đọc câu mô tả -> vé hành trình; F2 tóm tắt lịch. Không ghi vào chuyến đi.
    Route::post('/len-ke-hoach/hieu-yeu-cau', [PlannerAiController::class, 'brief'])->middleware('throttle:10,1,planner-ai-brief')->name('planner.ai.brief');
    Route::get('/len-ke-hoach/tro-ly', [PlannerAiController::class, 'chatPage'])->name('planner.ai.chat');
    Route::post('/len-ke-hoach/tro-ly/doc', [PlannerAiController::class, 'chatRead'])->middleware('throttle:30,1,planner-ai-chat')->name('planner.ai.chat.read');
    Route::post('/len-ke-hoach/tro-ly/tao', [PlannerAiController::class, 'chatCreate'])->middleware('throttle:6,1,planner-ai-make')->name('planner.ai.chat.make');
    Route::post('/len-ke-hoach/{id}/tom-tat-ai', [PlannerAiController::class, 'explain'])->whereNumber('id')->middleware('throttle:10,1,planner-ai-explain')->name('planner.ai.explain');
    Route::post('/len-ke-hoach/{id}/la-ban', [PlannerAiController::class, 'copilot'])->whereNumber('id')->middleware('throttle:12,1,planner-ai-copilot')->name('planner.ai.copilot');
    Route::post('/len-ke-hoach/{id}/la-ban/goi-y', [PlannerAiController::class, 'suggest'])->whereNumber('id')->middleware('throttle:30,1,planner-ai-suggest')->name('planner.ai.suggest');
    Route::post('/len-ke-hoach/{id}/la-ban/goi-y/ap-dung', [PlannerAiController::class, 'suggestApply'])->whereNumber('id')->middleware('throttle:30,1,planner-ai-suggest-apply')->name('planner.ai.suggest.apply');
    Route::post('/len-ke-hoach/{id}/la-ban/kiem-tra-lai', [PlannerAiController::class, 'recheck'])->whereNumber('id')->middleware('throttle:30,1,planner-ai-recheck')->name('planner.ai.recheck');
    Route::post('/len-ke-hoach/{id}/la-ban/ket-qua', [PlannerAiController::class, 'feedback'])->whereNumber('id')->middleware('throttle:60,1,planner-ai-feedback')->name('planner.ai.feedback');
    Route::post('/la-ban/danh-gia', [PlannerAiController::class, 'rate'])->middleware('throttle:30,1,planner-ai-rate')->name('planner.ai.rate');
    Route::post('/len-ke-hoach/{id}/trang-thai', [PlannerController::class, 'status'])->whereNumber('id')->middleware('throttle:60,1,planner-status')->name('planner.status');
    Route::post('/len-ke-hoach', [PlannerController::class, 'store'])->middleware('throttle:20,1,planner-store')->name('planner.store');
    Route::get('/len-ke-hoach/{id}', [PlannerController::class, 'edit'])->whereNumber('id')->name('planner.edit');
    Route::get('/len-ke-hoach/{id}/tim-ngoai', [PlannerController::class, 'searchExternal'])->whereNumber('id')->middleware('throttle:40,1,planner-geo')->name('planner.searchExternal');
    Route::get('/len-ke-hoach/{id}/kham-pha', [PlannerController::class, 'browse'])->whereNumber('id')->middleware('throttle:60,1,planner-browse')->name('planner.browse');
    Route::get('/len-ke-hoach/{id}/tim', [PlannerController::class, 'search'])->whereNumber('id')->middleware('throttle:90,1,planner-search')->name('planner.search');
    Route::post('/len-ke-hoach/{id}/luu', [PlannerController::class, 'save'])->whereNumber('id')->middleware('throttle:60,1,planner-save')->name('planner.save');
    Route::get('/len-ke-hoach/{id}/do-mang-theo', [PlannerController::class, 'packing'])->whereNumber('id')->middleware('throttle:20,1,planner-packing')->name('planner.packing');
    Route::get('/len-ke-hoach/{id}/xuat-pdf', [PlannerController::class, 'exportPdf'])->whereNumber('id')->middleware('throttle:20,1,planner-pdf')->name('planner.pdf');
    // T-36…T-38: chuyến bay, trạng thái chuyến bay, khách sạn (tham khảo, có nhãn khi là dữ liệu thử nghiệm)
    Route::get('/len-ke-hoach/{id}/chuyen-bay', [TravelController::class, 'flights'])->whereNumber('id')->middleware('throttle:20,1,travel-flight')->name('travel.flights');
    Route::post('/len-ke-hoach/{id}/chuyen-bay', [TravelController::class, 'addFlight'])->whereNumber('id')->middleware('throttle:20,1,travel-add')->name('travel.flights.add');
    Route::get('/len-ke-hoach/{id}/chuyen-bay/trang-thai', [TravelController::class, 'status'])->whereNumber('id')->middleware('throttle:20,1,travel-status')->name('travel.status');
    Route::get('/len-ke-hoach/{id}/khach-san', [TravelController::class, 'hotels'])->whereNumber('id')->middleware('throttle:20,1,travel-hotel')->name('travel.hotels');
    Route::post('/len-ke-hoach/{id}/khach-san', [TravelController::class, 'addHotel'])->whereNumber('id')->middleware('throttle:20,1,travel-add')->name('travel.hotels.add');
    Route::post('/len-ke-hoach/{id}/ngoai/{externalId}/xoa', [TravelController::class, 'remove'])->whereNumber(['id', 'externalId'])->middleware('throttle:30,1,travel-rm')->name('travel.remove');
    Route::post('/len-ke-hoach/{id}/xac-nhan', [TravelController::class, 'addConfirmation'])->whereNumber('id')->middleware('throttle:20,1,travel-confirm')->name('travel.confirm');
    Route::get('/len-ke-hoach/{id}/xac-nhan/{externalId}/tep', [TravelController::class, 'confirmationFile'])->whereNumber(['id', 'externalId'])->name('travel.confirm.file');
    Route::post('/len-ke-hoach/{id}/khoa', [PlannerController::class, 'lock'])->whereNumber('id')->middleware('throttle:30,1,planner-lock')->name('planner.lock');
    Route::get('/len-ke-hoach/{id}/lich-su', [PlannerController::class, 'history'])->whereNumber('id')->middleware('throttle:60,1,planner-history')->name('planner.history');
    Route::get('/len-ke-hoach/{id}/lich-su/{revisionNo}', [PlannerController::class, 'historyShow'])->whereNumber(['id', 'revisionNo'])->middleware('throttle:60,1,planner-history')->name('planner.historyShow');
    Route::post('/len-ke-hoach/{id}/lich-su/{revisionNo}/khoi-phuc', [PlannerController::class, 'restoreRevision'])->whereNumber(['id', 'revisionNo'])->middleware('throttle:20,1,planner-restore')->name('planner.restoreRevision');
    Route::get('/len-ke-hoach/{id}/phien-ban', [PlannerController::class, 'revision'])->whereNumber('id')->middleware('throttle:120,1,planner-rev')->name('planner.revision');
    Route::get('/len-ke-hoach/{id}/thanh-vien', [PlannerController::class, 'members'])->whereNumber('id')->middleware('throttle:60,1,planner-members')->name('planner.members');
    Route::post('/len-ke-hoach/{id}/thanh-vien', [PlannerController::class, 'invite'])->whereNumber('id')->middleware('throttle:20,1,planner-invite')->name('planner.invite');
    Route::post('/len-ke-hoach/{id}/thanh-vien/{userId}', [PlannerController::class, 'memberRole'])->whereNumber(['id', 'userId'])->middleware('throttle:30,1,planner-memrole')->name('planner.memberRole');
    Route::post('/len-ke-hoach/{id}/thanh-vien/{userId}/go', [PlannerController::class, 'memberRemove'])->whereNumber(['id', 'userId'])->middleware('throttle:30,1,planner-memrm')->name('planner.memberRemove');
    Route::post('/len-ke-hoach/{id}/chia-se', [PlannerController::class, 'share'])->whereNumber('id')->middleware('throttle:30,1,planner-share')->name('planner.share');
    Route::post('/lich-trinh/{slug}/dung-lam-chuyen-di', [PlannerController::class, 'fromItinerary'])->where('slug', '[a-z0-9-]+')->middleware('throttle:10,1,itin-to-trip')->name('planner.fromItinerary');
    Route::get('/len-ke-hoach/{id}/phuong-an-mua', [PlannerController::class, 'rainSuggest'])->whereNumber('id')->middleware('throttle:60,1,planner-rain')->name('planner.rainSuggest');
    Route::post('/tour/{id}/len-ke-hoach', [PlannerController::class, 'fromTour'])->whereNumber('id')->middleware('throttle:10,1,tour-to-trip')->name('planner.fromTour');
    Route::get('/len-ke-hoach/{id}/quanh-tuyen', [PlannerController::class, 'around'])->whereNumber('id')->middleware('throttle:60,1,planner-around')->name('planner.around');
    Route::get('/len-ke-hoach/{id}/lap-khoang-trong', [PlannerController::class, 'gapFill'])->whereNumber('id')->middleware('throttle:60,1,planner-gap')->name('planner.gapFill');
    Route::post('/len-ke-hoach/{id}/toi-uu', [PlannerController::class, 'optimize'])->whereNumber('id')->middleware('throttle:60,1,planner-opt')->name('planner.optimize');
    Route::get('/len-ke-hoach/{id}/suc-khoe', [PlannerController::class, 'health'])->whereNumber('id')->middleware('throttle:60,1,planner-health')->name('planner.health');
    Route::get('/len-ke-hoach/{id}/suc-khoe/xu-ly', [PlannerController::class, 'resolve'])->whereNumber('id')->middleware('throttle:60,1,planner-resolve')->name('planner.resolve');
    Route::post('/len-ke-hoach/{id}/nhan-ban', [PlannerController::class, 'duplicate'])->whereNumber('id')->middleware('throttle:10,1,planner-dup')->name('planner.duplicate');
    // Danh sách chờ, đổi số khách của đơn, khiếu nại sau tour
    Route::get('/danh-sach-cho', [WaitlistController::class, 'index'])->name('waitlist.index');
    Route::post('/danh-sach-cho', [WaitlistController::class, 'join'])->middleware('throttle:10,1,waitlist-join')->name('waitlist.join');
    Route::post('/danh-sach-cho/{id}/rut', [WaitlistController::class, 'cancel'])->whereNumber('id')->middleware('throttle:20,1,waitlist-cancel')->name('waitlist.cancel');
    Route::get('/danh-sach-cho/moi/{token}', [WaitlistController::class, 'offer'])->where('token', '[A-Za-z0-9]{40}')->name('waitlist.offer');
    Route::post('/danh-sach-cho/moi/{token}', [WaitlistController::class, 'accept'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,waitlist-accept')->name('waitlist.accept');
    Route::post('/dat-tour/{bookingId}/doi-so-khach', [BookingAdjustController::class, 'adjust'])->whereNumber('bookingId')->middleware('throttle:15,1,booking-adjust')->name('booking.adjust');
    Route::get('/khieu-nai', [ComplaintController::class, 'index'])->name('complaint.index');
    Route::post('/khieu-nai', [ComplaintController::class, 'store'])->middleware('throttle:5,10,complaint-create')->name('complaint.store');
    Route::get('/khieu-nai/{code}', [ComplaintController::class, 'show'])->where('code', 'KN[0-9A-Z]{10}')->name('complaint.show');
    Route::post('/khieu-nai/{code}', [ComplaintController::class, 'reply'])->where('code', 'KN[0-9A-Z]{10}')->middleware('throttle:20,10,complaint-reply')->name('complaint.reply');
    // Yêu cầu tư vấn với nhân viên Travela (T-39)
    Route::get('/ho-tro', [SupportTicketController::class, 'index'])->name('support.index');
    Route::post('/ho-tro', [SupportTicketController::class, 'store'])->middleware('throttle:5,10,support-create')->name('support.store');
    Route::get('/ho-tro/{code}', [SupportTicketController::class, 'show'])->where('code', 'TV[0-9A-Z]{10}')->name('support.show');
    Route::post('/ho-tro/{code}', [SupportTicketController::class, 'reply'])->where('code', 'TV[0-9A-Z]{10}')->middleware('throttle:20,1,support-msg')->name('support.reply');
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
        // Ẩn / hiện đánh giá: nhân viên cũng làm được (§12.2), xóa hẳn chỉ admin / manager
        Route::post('/reviews/{id}/toggle', [ReviewManagementController::class, 'toggle'])->whereNumber('id')->name('admin.reviews.toggle');

        // Hồ sơ của chính mình: mọi vai trò (danh sách quản lý / nhân viên hiện theo quyền)
        Route::get('/admin', [AdminManagementController::class, 'index'])->name('admin.admin');
        Route::post('/update-admin', [AdminManagementController::class, 'updateAdmin'])->name('admin.update-admin');
        Route::post('/update-avatar', [AdminManagementController::class, 'updateAvatar'])->name('admin.update-avatar');

        // Xem chuyến đi của khách (chỉ đọc) để tư vấn
        Route::get('/trips/{id}', [TripAdminController::class, 'show'])->whereNumber('id')->name('admin.trips.show');
        // Đặt tour hộ khách tại văn phòng
        Route::get('/booking/create', [BookingManagementController::class, 'create'])->name('admin.booking.create');
        Route::post('/booking/create', [BookingManagementController::class, 'store'])->middleware('throttle:20,1,admin-booking-create')->name('admin.booking.store');
        Route::get('/booking/departures', [BookingManagementController::class, 'departures'])->name('admin.booking.departures');

        // Yêu cầu tư vấn (T-39)
        Route::get('/support', [SupportTicketAdminController::class, 'index'])->name('admin.support');
        Route::get('/support/{id}', [SupportTicketAdminController::class, 'show'])->whereNumber('id')->name('admin.support.show');
        Route::post('/support/{id}/reply', [SupportTicketAdminController::class, 'reply'])->whereNumber('id')->middleware('throttle:30,1,support-reply')->name('admin.support.reply');
        Route::post('/support/{id}/status', [SupportTicketAdminController::class, 'status'])->whereNumber('id')->name('admin.support.status');
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
        Route::post('/delete-temp-image', [ToursManagementController::class, 'deleteTempImage'])->name('admin.delete-temp-image');
        Route::post('/upload-tour-image', [ToursManagementController::class, 'uploadTourImage'])->name('admin.upload-tour-image');
        Route::post('/add-timeline', [ToursManagementController::class, 'addTimeline'])->name('admin.add-timeline');

        //Management User
        Route::get('/users', [UserManagementController::class, 'index'])->name('admin.users');
        Route::get('/users/{id}', [UserManagementController::class, 'show'])->whereNumber('id')->name('admin.users.show');
        Route::post('/active-user', [UserManagementController::class, 'activeUser'])->name('admin.active-user');
        Route::post('/status-user', [UserManagementController::class, 'changeStatus'])->name('admin.status-user');

        //Management Booking - thêm quyền
        Route::post('/finish-booking', [BookingManagementController::class, 'finishBooking'])->name('admin.finish-booking');
        Route::post('/admin-cancel-booking', [BookingManagementController::class, 'cancelBooking'])->name('admin.cancel-booking');
        Route::post('/booking/{id}/no-show', [BookingManagementController::class, 'noShow'])->whereNumber('id')->name('admin.booking.noshow');
        Route::post('/booking/{id}/no-show/undo', [BookingManagementController::class, 'noShowUndo'])->whereNumber('id')->name('admin.booking.noshow.undo');
        Route::post('/received-money', [BookingManagementController::class, 'receiviedMoney'])->name('admin.received');
        Route::post('/confirm-payment', [BookingManagementController::class, 'confirmPayment'])->name('admin.confirm-payment');
        Route::post('/send-pdf', [BookingManagementController::class, 'sendPdf'])->middleware('throttle:10,1,send-pdf')->name('admin.send.pdf');
        Route::get('/cancel-preview', [BookingManagementController::class, 'cancelPreview'])->name('admin.cancel-preview');

        // Thanh toán trực tuyến: nhật ký, đối soát, xác nhận tay chuyển khoản QR
        Route::get('/payments', [PaymentAdminController::class, 'index'])->name('admin.payments');
        Route::post('/payments/{bookingId}/reconcile', [PaymentAdminController::class, 'reconcile'])->whereNumber('bookingId')->middleware('throttle:20,1,pay-reconcile')->name('admin.payments.reconcile');
        Route::post('/payments/{bookingId}/confirm', [PaymentAdminController::class, 'confirm'])->whereNumber('bookingId')->middleware('throttle:20,1,pay-confirm')->name('admin.payments.confirm');

        // Hoàn tiền (A6)
        Route::get('/refunds', [RefundManagementController::class, 'index'])->name('admin.refunds');
        Route::post('/refunds/{id}/complete', [RefundManagementController::class, 'complete'])->whereNumber('id')->name('admin.refunds.complete');
        Route::post('/refunds/{id}/gateway', [RefundManagementController::class, 'gateway'])->whereNumber('id')->middleware('throttle:10,1,refund-gateway')->name('admin.refunds.gateway');
        Route::post('/refunds/{id}/sync', [RefundManagementController::class, 'sync'])->whereNumber('id')->middleware('throttle:30,1,refund-sync')->name('admin.refunds.sync');

        // Lịch khởi hành (A3 / D-02)
        Route::get('/departures', [DepartureManagementController::class, 'index'])->name('admin.departures');
        Route::post('/departures', [DepartureManagementController::class, 'store'])->name('admin.departures.store');
        Route::post('/departures/{id}', [DepartureManagementController::class, 'update'])->whereNumber('id')->name('admin.departures.update');
        Route::post('/departures/{id}/toggle', [DepartureManagementController::class, 'toggle'])->whereNumber('id')->name('admin.departures.toggle');
        Route::post('/departures/{id}/delete', [DepartureManagementController::class, 'destroy'])->whereNumber('id')->name('admin.departures.destroy');

        // Xếp xe / hướng dẫn viên cho đoàn theo khung giờ (ResourceAllocationService)
        Route::get('/resources', [\App\Http\Controllers\admin\ResourceScheduleController::class, 'index'])->name('admin.resources');
        Route::post('/resources', [\App\Http\Controllers\admin\ResourceScheduleController::class, 'store'])->name('admin.resources.store');
        Route::post('/resources/allocate', [\App\Http\Controllers\admin\ResourceScheduleController::class, 'allocate'])->middleware('throttle:60,1,resource-allocate')->name('admin.resources.allocate');
        Route::post('/resources/allocations/{id}/release', [\App\Http\Controllers\admin\ResourceScheduleController::class, 'release'])->whereNumber('id')->name('admin.resources.release');
        Route::post('/resources/{id}/toggle', [\App\Http\Controllers\admin\ResourceScheduleController::class, 'toggle'])->whereNumber('id')->name('admin.resources.toggle');

        // Ghép đoàn vận hành (không thay đổi booking hoặc tour đã bán)
        Route::get('/departure-groups', [DepartureGroupingController::class, 'index'])->name('admin.departures.grouping');
        Route::get('/departure-rescue', [DepartureRescueController::class, 'index'])->name('admin.departures.rescue');
        Route::get('/incidents', [TourIncidentController::class, 'index'])->name('admin.incidents');
        Route::post('/incidents', [TourIncidentController::class, 'store'])->name('admin.incidents.store');
        Route::get('/incidents/{id}', [TourIncidentController::class, 'show'])->whereNumber('id')->name('admin.incidents.show');
        Route::post('/incidents/{id}/status', [TourIncidentController::class, 'status'])->whereNumber('id')->name('admin.incidents.status');
        Route::post('/incidents/{id}/note', [TourIncidentController::class, 'note'])->whereNumber('id')->name('admin.incidents.note');
        Route::post('/incidents/{id}/notify', [TourIncidentController::class, 'notify'])->whereNumber('id')->middleware('throttle:6,1,incident-notify')->name('admin.incidents.notify');
        Route::get('/waitlist', [WaitlistAdminController::class, 'index'])->name('admin.waitlist');
        Route::post('/waitlist/process', [WaitlistAdminController::class, 'process'])->middleware('throttle:6,1,waitlist-process')->name('admin.waitlist.process');
        Route::post('/waitlist/departure/{availabilityId}/offer', [WaitlistAdminController::class, 'offer'])->whereNumber('availabilityId')->name('admin.waitlist.offer');
        Route::post('/waitlist/{id}/cancel', [WaitlistAdminController::class, 'cancel'])->whereNumber('id')->name('admin.waitlist.cancel');
        Route::get('/adjustments', [BookingAdjustmentAdminController::class, 'index'])->name('admin.adjustments');
        Route::post('/adjustments/{id}/settle', [BookingAdjustmentAdminController::class, 'settle'])->whereNumber('id')->name('admin.adjustments.settle');
        Route::post('/adjustments/{id}/gateway', [BookingAdjustmentAdminController::class, 'gateway'])->whereNumber('id')->middleware('throttle:10,1,adjust-gateway')->name('admin.adjustments.gateway');
        Route::post('/adjustments/{id}/gateway/sync', [BookingAdjustmentAdminController::class, 'gatewaySync'])->whereNumber('id')->middleware('throttle:30,1,adjust-sync')->name('admin.adjustments.gateway.sync');
        Route::post('/bookings/{bookingId}/adjust', [BookingAdjustmentAdminController::class, 'adjust'])->whereNumber('bookingId')->name('admin.booking.adjust');
        Route::get('/feasibility', [FeasibilityController::class, 'index'])->name('admin.feasibility');
        Route::get('/feasibility/{id}', [FeasibilityController::class, 'show'])->whereNumber('id')->name('admin.feasibility.show');
        Route::get('/profit', [ProfitController::class, 'index'])->name('admin.profit');
        Route::get('/profit/{id}', [ProfitController::class, 'show'])->whereNumber('id')->name('admin.profit.show');
        Route::post('/profit/{id}/costs', [ProfitController::class, 'addCost'])->whereNumber('id')->name('admin.profit.cost.add');
        Route::post('/profit/{id}/costs/{costId}/actual', [ProfitController::class, 'costStatus'])->whereNumber(['id', 'costId'])->name('admin.profit.cost.actual');
        Route::post('/profit/{id}/costs/{costId}/delete', [ProfitController::class, 'deleteCost'])->whereNumber(['id', 'costId'])->name('admin.profit.cost.delete');
        Route::post('/profit/{id}/rate', [ProfitController::class, 'rate'])->whereNumber('id')->name('admin.profit.rate');
        Route::get('/travel-alerts', [\App\Http\Controllers\admin\TravelAlertAdminController::class, 'index'])->name('admin.travel-alerts');
        Route::post('/travel-alerts', [\App\Http\Controllers\admin\TravelAlertAdminController::class, 'store'])->name('admin.travel-alerts.store');
        Route::post('/travel-alerts/{id}', [\App\Http\Controllers\admin\TravelAlertAdminController::class, 'update'])->whereNumber('id')->name('admin.travel-alerts.update');
        Route::post('/travel-alerts/{id}/toggle', [\App\Http\Controllers\admin\TravelAlertAdminController::class, 'toggle'])->whereNumber('id')->name('admin.travel-alerts.toggle');
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('admin.suppliers');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('admin.suppliers.store');
        Route::get('/suppliers/{id}', [SupplierController::class, 'show'])->whereNumber('id')->name('admin.suppliers.show');
        Route::post('/suppliers/{id}/toggle', [SupplierController::class, 'toggle'])->whereNumber('id')->name('admin.suppliers.toggle');
        Route::get('/complaints', [ComplaintAdminController::class, 'index'])->name('admin.complaints');
        Route::get('/complaints/{id}', [ComplaintAdminController::class, 'show'])->whereNumber('id')->name('admin.complaints.show');
        Route::post('/complaints/{id}/reply', [ComplaintAdminController::class, 'reply'])->whereNumber('id')->name('admin.complaints.reply');
        Route::post('/complaints/{id}/status', [ComplaintAdminController::class, 'status'])->whereNumber('id')->name('admin.complaints.status');
        Route::post('/complaints/{id}/compensation', [ComplaintAdminController::class, 'compensation'])->whereNumber('id')->name('admin.complaints.compensation');
        Route::post('/complaints/{id}/pay', [ComplaintAdminController::class, 'pay'])->whereNumber('id')->name('admin.complaints.pay');
        Route::get('/reconciliation', [ReconciliationController::class, 'index'])->name('admin.reconciliation');
        Route::post('/reconciliation/run', [ReconciliationController::class, 'run'])->middleware('throttle:6,1,recon-run')->name('admin.reconciliation.run');
        Route::post('/reconciliation/{id}/ignore', [ReconciliationController::class, 'ignore'])->whereNumber('id')->name('admin.reconciliation.ignore');
        Route::post('/reconciliation/{id}/reopen', [ReconciliationController::class, 'reopen'])->whereNumber('id')->name('admin.reconciliation.reopen');
        Route::post('/departure-rescue', [DepartureRescueController::class, 'store'])->name('admin.departures.rescue.store');
        Route::post('/departure-rescue/{id}/apply', [DepartureRescueController::class, 'apply'])->whereNumber('id')->name('admin.departures.rescue.apply');
        Route::post('/departure-rescue/resources', [DepartureRescueController::class, 'addResource'])->name('admin.departures.rescue.resources');
        Route::post('/departure-rescue/{id}/refund', [DepartureRescueController::class, 'refund'])->whereNumber('id')->name('admin.departures.rescue.refund');
        Route::post('/departure-rescue/{id}/cancel', [DepartureRescueController::class, 'cancel'])->whereNumber('id')->name('admin.departures.rescue.cancel');
        Route::post('/departure-groups/merge', [DepartureGroupingController::class, 'merge'])->name('admin.departures.grouping.merge');
        Route::post('/departure-groups/{id}/disband', [DepartureGroupingController::class, 'disband'])->whereNumber('id')->name('admin.departures.grouping.disband');
        Route::post('/departures/{id}/minimum', [DepartureGroupingController::class, 'minimum'])->whereNumber('id')->name('admin.departures.minimum');

        // Nhật ký thao tác (D-08)
        Route::get('/audit-log', [AuditLogController::class, 'index'])->name('admin.audit');

        //Promotion
        Route::get('/promotion', [PromotionManagementController::class, 'index'])->name('admin.promotion');
        Route::post('/add-promotion', [PromotionManagementController::class, 'addPromotion'])->name('admin.add-promotion');
        Route::get('/promotion-edit/{id}', [PromotionManagementController::class, 'getPromotionEdit'])->name('admin.promotion-edit');
        Route::post('/edit-promotion', [PromotionManagementController::class, 'editPromotion'])->name('admin.edit-promotion');
        Route::post('/delete-promotion', [PromotionManagementController::class, 'deletePromotion'])->name('admin.delete-promotion');

        // Duyệt & quản lý nội dung Khám phá
        Route::get('/content-overview', [ContentOverviewController::class, 'index'])->name('admin.content.overview');
        Route::get('/content-quality', [ContentQualityController::class, 'index'])->name('admin.content.quality');
        Route::post('/content-quality/snapshot', [ContentQualityController::class, 'snapshot'])->middleware('throttle:10,1')->name('admin.content.quality.snapshot');
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
        Route::get('/chat-logs/ai', [ChatLogController::class, 'ai'])->name('admin.chat.ai');
        Route::get('/chat-logs/ai/export', [ChatLogController::class, 'aiExport'])->name('admin.chat.ai.export');

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
        Route::post('/add-staff', [AdminManagementController::class, 'addStaff'])->name('admin.add-staff');
        Route::post('/delete-staff', [AdminManagementController::class, 'deleteStaff'])->name('admin.delete-staff');

        // Admin v2
        Route::get('/booking-export', [BookingManagementController::class, 'export'])->name('admin.booking.export');
        Route::get('/trips', [TripAdminController::class, 'index'])->name('admin.trips');
        Route::post('/trips/{id}/delete', [TripAdminController::class, 'destroy'])->whereNumber('id')->name('admin.trips.destroy');
        Route::post('/trips/{id}/unpublish', [TripAdminController::class, 'takeDown'])->whereNumber('id')->name('admin.trips.unpublish');
        Route::get('/content-requests', [ContentRequestAdminController::class, 'index'])->name('admin.content-requests');
        Route::post('/content-requests/{id}/resolve', [ContentRequestAdminController::class, 'resolve'])->whereNumber('id')->name('admin.content-requests.resolve');
        Route::get('/system', [SystemController::class, 'index'])->name('admin.system');
        Route::post('/system/flush-content', [SystemController::class, 'flushContent'])->name('admin.system.flush');
        Route::post('/system/clear-views', [SystemController::class, 'clearViews'])->name('admin.system.views');
    });

    // Chỉ admin
    Route::middleware(['checkAdminRole:admin'])->group(function () {
        //Management Admin

        //Management Manager - admin thêm/xóa manager
        Route::post('/add-manager', [AdminManagementController::class, 'addManager'])->name('admin.add-manager');
        Route::post('/delete-manager', [AdminManagementController::class, 'deleteManager'])->name('admin.delete-manager');
    });
});

if (app()->environment('local')) {
    Route::view('/ui-kit', 'clients.ui-kit')->name('ui-kit');
}
