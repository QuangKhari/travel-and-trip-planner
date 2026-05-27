@include('clients.blocks.header')
@include('clients.blocks.banner')

        <!-- Page Banner Start -->
        <section class="page-banner-two rel z-1">
            <div class="container-fluid">
                <hr class="mt-0">
                <div class="container">
                    <div class="banner-inner pt-15 pb-25">
                        <h2 class="page-title mb-10" data-aos="fade-left" data-aos-duration="1500" data-aos-offset="50">{{ $tourDetail->destination }}</h2>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb justify-content-center mb-20" data-aos="fade-right" data-aos-delay="200" data-aos-duration="1500" data-aos-offset="50">
                                <li class="breadcrumb-item"><a href="index.html">Trang chủ</a></li>
                                <li class="breadcrumb-item active">{{ $title }}</li>
                            </ol>
                        </nav>
                    </div>
                </div>
            </div>
        </section>
        <!-- Page Banner End -->
        
        
        <!-- Tour Gallery start -->
        <div class="tour-gallery">
            <div class="container-fluid">
                <div class="row gap-10 justify-content-center rel">
                    <div class="col-lg-4 col-md-6">
                        <div class="gallery-item">
                            <img src="{{ asset('admin/assets/images/gallery-tours/' . ($tourDetail->images[0] ?? 'default.jpg')) }}" alt="Destination">
                        </div>
                        <div class="gallery-item">
                            <img src="{{ asset('admin/assets/images/gallery-tours/' . ($tourDetail->images[1] ?? 'default.jpg')) }}" alt="Destination">
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <div class="gallery-item">
                            <img src="{{ asset('admin/assets/images/gallery-tours/' . ($tourDetail->images[2] ?? 'default.jpg')) }}" alt="Destination">
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <div class="gallery-item">
                            <img src="{{ asset('admin/assets/images/gallery-tours/' . ($tourDetail->images[3] ?? 'default.jpg')) }}" alt="Destination">
                        </div>
                        <div class="gallery-item">
                            <img src="{{ asset('admin/assets/images/gallery-tours/' . ($tourDetail->images[4] ?? 'default.jpg')) }}" alt="Destination">
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>
        <!-- Tour Gallery End -->
        
        
        <!-- Tour Header Area start -->
        <section class="tour-header-area pt-70 rel z-1">
            <div class="container">
                <div class="row justify-content-between">
                    <div class="col-xl-6 col-lg-7">
                        <div class="tour-header-content mb-15" data-aos="fade-left" data-aos-duration="1500" data-aos-offset="50">
                            <span class="location d-inline-block mb-10"><i class="fal fa-map-marker-alt"></i> {{$tourDetail->destination}}</span>
                            <div class="section-title pb-5">
                                <h2>{{$tourDetail->title}}</h2>
                            </div>
                            <div class="ratting">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star-half-alt"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-lg-5 text-lg-end" data-aos="fade-right" data-aos-duration="1500" data-aos-offset="50">
                        <div class="tour-header-social mb-10">
                            <a href="#"><i class="far fa-share-alt"></i>Share tours</a>
                            <a href="#"><i class="fas fa-heart bgc-secondary"></i>Wish list</a>
                        </div>
                    </div>
                </div>
                <hr class="mt-50 mb-70">
            </div>
        </section>
        <!-- Tour Header Area end -->
        
        
        <!-- Tour Details Area start -->
        <section class="tour-details-page pb-100">
            <div class="container">
                <div class="row">
                    <div class="col-lg-8">
                        <div class="tour-details-content">
                            <h3>Khám phá Tours</h3>
                            <p> {!! $tourDetail->description !!} </p>
                            <div class="row pb-55">
                                <div class="col-md-6">
                                    <div class="tour-include-exclude mt-30">
                                        <h5>Bao gồm và không bao gồm</h5>
                                        <ul class="list-style-one check mt-25">
                                            <li><i class="far fa-check"></i> Dịch vụ chọn và thả</li>
                                            <li><i class="far fa-check"></i> 1 bữa ăn mỗi ngày</li>
                                            <li><i class="far fa-check"></i> Tiệc tối trên du thuyền và sự kiện âm nhạc</li>
                                            <li><i class="far fa-check"></i> Khám phá 7 địa điểm tuyệt vời nhất trong thành phố</li>
                                            <li><i class="far fa-check"></i> Nước đóng chai trên xe buýt</li>
                                            <li><i class="far fa-check"></i> Giao thông bằng xe du lịch cao cấp</li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="tour-include-exclude mt-30">
                                        <h5>Không bao gồm</h5>
                                        <ul class="list-style-one mt-25">
                                            <li><i class="far fa-times"></i> Tip</li>
                                            <li><i class="far fa-times"></i> Nhận phòng và trả phòng tại khách sạn</li>
                                            <li><i class="far fa-times"></i> Bữa trưa, đồ ăn và đồ uống</li>
                                            <li><i class="far fa-times"></i> Tùy chọn nâng cấp lên kính</li>
                                            <li><i class="far fa-times"></i> Dịch vụ bổ sung</li>
                                            <li><i class="far fa-times"></i> Bảo hiểm</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        

                        <h3>Lịch trình</h3>
                        <div class="accordion-two mt-25 mb-60" id="faq-accordion-two">
                            @php
                                $day = 1;
                            @endphp
                            @foreach ($tourDetail->timeline as $timeline )
                                <div class="accordion-item">
                                    <h5 class="accordion-header">
                                        <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#collapseTwo{{ $timeline->timeLineId}}">
                                        Ngày {{ $day++ }} - {{ $timeline->title }}
                                        </button>
                                    </h5>
                                    <div id="collapseTwo{{ $timeline->timeLineId}}" class="accordion-collapse collapse" data-bs-parent="#faq-accordion-two">
                                        <div class="accordion-body">
                                            <p>{{ $timeline->description }}</p>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <h3>Maps</h3>
                        <div class="tour-map mt-30 mb-50">
                            <iframe src="https://www.google.com/maps/embed?pb=!1m10!1m8!1m3!1d96777.16150026117!2d-74.00840582560909!3d40.71171357405996!3m2!1i1024!2i768!4f13.1!5e0!3m2!1sen!2sbd!4v1706508986625!5m2!1sen!2sbd" style="border:0; width: 100%;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                        </div>

                        <div id="partials_reviews">
                    @include('clients.partials.reviews')
                </div>

                <h3 class="{{ $checkDisplay }}">Thêm Đánh giá</h3>
                <form id="comment-form" class="comment-form bgc-lighter z-1 rel mt-30 {{ $checkDisplay }}"
                    name="review-form" action="{{ route('reviews') }}" method="post" data-aos="fade-up"
                    data-aos-duration="1500" data-aos-offset="50">
                    @csrf
                    <div class="comment-review-wrap">
                        <div class="comment-ratting-item">
                            <span class="title">Đánh giá</span>
                            <div class="ratting" id="rating-stars">
                                <i class="far fa-star" data-value="1"></i>
                                <i class="far fa-star" data-value="2"></i>
                                <i class="far fa-star" data-value="3"></i>
                                <i class="far fa-star" data-value="4"></i>
                                <i class="far fa-star" data-value="5"></i>
                            </div>
                        </div>

                    </div>
                    <hr class="mt-30 mb-40">
                    <h5>Để lại phản hồi</h5>
                    <div class="row gap-20 mt-20">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="message">Nội dung</label>
                                <textarea name="message" id="message" class="form-control" rows="5" required=""></textarea>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-group mb-0">
                                <button type="submit" class="theme-btn bgc-secondary style-two" id="submit-reviews"
                                    data-url-checkBooking="{{ route('checkBooking') }}"
                                    data-tourId-reviews="{{ $tourDetail->tourId }}">
                                    <span data-hover="Gửi đánh giá">Gửi đánh giá</span>
                                    <i class="fal fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

            </div>
            <div class="col-lg-4 col-md-8 col-sm-10 rmt-75">
                <div class="blog-sidebar tour-sidebar">

                    <div class="widget widget-booking" data-aos="fade-up" data-aos-duration="1500"
                        data-aos-offset="50">
                        <h5 class="widget-title">Tour Booking</h5>
                        <form action="{{ route('booking', ['id' => $tourDetail->tourId]) }}" method="POST">
                            @csrf
                            <div class="date mb-25">
                                <b>Ngày bắt đầu</b>
                                <input type="text" value="{{ date('d-m-Y', strtotime($tourDetail->startDate)) }}"
                                    name="startdate" disabled>
                            </div>
                            <hr>
                            <div class="date mb-25">
                                <b>Ngày kết thúc</b>
                                <input type="text" value="{{ date('d-m-Y', strtotime($tourDetail->endDate)) }}"
                                    name="enddate" disabled>
                            </div>
                            <hr>
                            <div class="time py-5">
                                <b>Thời gian :</b>
                                <p>{{ $tourDetail->time }}</p>
                                <input type="hidden" name="time">
                            </div>
                            <hr class="mb-25">
                            <h6>Vé:</h6>
                            <ul class="tickets clearfix">
                                <li>
                                    Người lớn <span
                                        class="price">{{ number_format($tourDetail->priceAdult, 0, ',', '.') }} VND
                                    </span>
                                </li>
                                <li>
                                    Trẻ em <span
                                        class="price">{{ number_format($tourDetail->priceChild, 0, ',', '.') }} VND
                                    </span>
                                </li>
                            </ul>
                            <button type="submit" class="theme-btn style-two w-100 mt-15 mb-5">
                                <span data-hover="Đặt ngay">Đặt ngay</span>
                                <i class="fal fa-arrow-right"></i>
                            </button>
                            <div class="text-center">
                                <a href="contact">Bạn cần trợ giúp không?</a>
                            </div>
                        </form>
                    </div>

                    <div class="widget widget-contact" data-aos="fade-up" data-aos-duration="1500"
                        data-aos-offset="50">
                        <h5 class="widget-title">Cần trợ giúp?</h5>
                        <ul class="list-style-one">
                            <li><i class="far fa-envelope"></i> <a
                                    href="emilto:minhdien.dev@gmail.com">aaa@gmail.com</a></li>
                            <li><i class="far fa-phone-volume"></i> <a href="callto:+000(123)45688">+000 (123) 456
                                    88</a></li>
                        </ul>
                    </div>
                    

                </div>
            </div>
        </div>
    </div>
</section>
        <!-- Tour Details Area end -->
        
        
@include('clients.blocks.new_letter')

@include('clients.blocks.footer')