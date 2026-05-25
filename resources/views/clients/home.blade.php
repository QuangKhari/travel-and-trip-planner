@include('clients.blocks.header_home')
@include('clients.blocks.banner_home')
        
        <!-- Destinations Area start -->
        <section class="destinations-area bgc-black pt-100 pb-70 rel z-1">
            <div class="container-fluid">
                <div class="row justify-content-center">
                    <div class="col-lg-12">
                        <div class="section-title text-white text-center counter-text-wrap mb-70" data-aos="fade-up" data-aos-duration="1500" data-aos-offset="50">
                            <h2>Khám phá du lịch cùng Travela</h2>
                            <p>Website <span class="count-text plus" data-speed="3000" data-stop="34500">0</span>Điểm đến mà bạn sẽ thích</p>
                        </div>
                    </div>
                </div>
                <div class="row justify-content-center">
                    @foreach ($tours as $tour)
                        <div class="col-xxl-3 col-xl-4 col-md-6">
                            <div class="destination-item block_tours" data-aos="fade-up" data-aos-duration="1500" data-aos-offset="50">
                                <div class="image">
                                    <div class="ratting"><i class="fas fa-star"></i> 4.8</div>
                                    <a href="#" class="heart"><i class="fas fa-heart"></i></a>
                                    <img src="{{ asset('clients/assets/images/gallery-tours/'.$tour->images[0].'') }}" alt="Destination">
                                </div>
                                <div class="content">
                                    <span class="location"><i class="fal fa-map-marker-alt"></i> {{ $tour->destination }}</span>
                                    <h5><a href="{{ route('tour-detail', ['id' => $tour->tourId]) }}">{{ $tour->title }}</a></h5>
                                    <span class="time">{{ $tour->time }}</span>
                                </div>
                                <div class="destination-footer">
                                    <span class="price"><span>${{ number_format($tour->priceAdult, 0, ',', '.') }}</span>/ người</span>
                                    <a href="{{ route('tour-detail', ['id' => $tour->tourId]) }}" class="read-more">Book Now <i class="fal fa-angle-right"></i></a>
                                </div>
                            </div>
                        </div>
                    @endforeach
                    </div>
                </div>
            </div>
        </section>
        <!-- Destinations Area end -->
         
         
        <!-- About Us Area start -->
        <section class="about-us-area py-100 rpb-90 rel z-1">
            <div class="container">
                <div class="row align-items-center">
                    <div class="col-xl-5 col-lg-6">
                        <div class="about-us-content rmb-55" data-aos="fade-left" data-aos-duration="1500" data-aos-offset="50">
                            <div class="section-title mb-25">
                                <h2>Top lí do chúng tôi được tin tưởng </h2>
                            </div>
                            <p>Chúng tôi se luôn nỗ lực để mang đến cho bạn những trải nghiệm du lịch tuyệt vời nhất, khám phá những điểm đến bí mật và những địa điểm không thể bỏ qua.</p>
                            <div class="divider counter-text-wrap mt-45 mb-55"><span>Chúng tôi có <span><span class="count-text plus" data-speed="3000" data-stop="5">0</span> Năm</span> kinh nghiệm</span></div>
                            <div class="row">
                                <div class="col-6">
                                    <div class="counter-item counter-text-wrap">
                                        <span class="count-text k-plus" data-speed="3000" data-stop="3">0</span>
                                        <span class="counter-title">Điểm đến phổ biến</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="counter-item counter-text-wrap">
                                        <span class="count-text m-plus" data-speed="3000" data-stop="9">0</span>
                                        <span class="counter-title">Khách hàng hài lòng</span>
                                    </div>
                                </div>
                            </div>
                            <a href="destination1.html" class="theme-btn mt-10 style-two">
                                <span data-hover="Khám phá điểm đến">Khám phá điểm đến</span>
                                <i class="fal fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                    <div class="col-xl-7 col-lg-6" data-aos="fade-right" data-aos-duration="1500" data-aos-offset="50">
                        <div class="about-us-image">
                            <div class="shape"><img src="{{ asset('clients/assets/images/about/shape1.png') }}" alt="Shape"></div>
                            <div class="shape"><img src="{{ asset('clients/assets/images/about/shape2.png') }}" alt="Shape"></div>
                            <div class="shape"><img src="{{ asset('clients/assets/images/about/shape3.png') }}" alt="Shape"></div>
                            <div class="shape"><img src="{{ asset('clients/assets/images/about/shape4.png') }}" alt="Shape"></div>
                            <div class="shape"><img src="{{ asset('clients/assets/images/about/shape5.png') }}" alt="Shape"></div>
                            <div class="shape"><img src="{{ asset('clients/assets/images/about/shape6.png') }}" alt="Shape"></div>
                            <div class="shape"><img src="{{ asset('clients/assets/images/about/shape7.png') }}" alt="Shape"></div>
                            <img src="{{ asset('clients/assets/images/about/about.png') }}" alt="About">
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <!-- About Us Area end -->
         
         
        <!-- Popular Destinations Area start -->
        <section class="popular-destinations-area rel z-1">
            <div class="container-fluid">
                <div class="popular-destinations-wrap br-20 bgc-lighter pt-100 pb-70">
                    <div class="row justify-content-center">
                        <div class="col-lg-12">
                            <div class="section-title text-center counter-text-wrap mb-70" data-aos="fade-up" data-aos-duration="1500" data-aos-offset="50">
                                <h2>Khám phá điểm đến phổ biến</h2>
                                <p>Website <span class="count-text plus" data-speed="3000" data-stop="34500">0</span> Trải nghiệm du lịch tuyệt vời</p>
                            </div>
                        </div>
                    </div>
                    <div class="container">
                        <div class="row justify-content-center">
                            <div class="col-xl-3 col-md-6">
                                <div class="destination-item style-two" data-aos="flip-up" data-aos-duration="1500" data-aos-offset="50">
                                    <div class="image">
                                        <a href="#" class="heart"><i class="fas fa-heart"></i></a>
                                        <img src="{{ asset('clients/assets/images/destinations/destination1.jpg') }}" alt="Destination">
                                    </div>
                                    <div class="content">
                                        <h6><a href="destination-details.html">Thailand beach</a></h6>
                                        <span class="time">5352+ tours & 856+ Điểm hoạt động</span>
                                        <a href="#" class="more"><i class="fas fa-chevron-right"></i></a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-md-6">
                                <div class="destination-item style-two" data-aos="flip-up" data-aos-delay="100" data-aos-duration="1500" data-aos-offset="50">
                                    <div class="image">
                                        <a href="#" class="heart"><i class="fas fa-heart"></i></a>
                                        <img src="{{ asset('clients/assets/images/destinations/destination2.jpg') }}" alt="Destination">
                                    </div>
                                    <div class="content">
                                        <h6><a href="destination-details.html">Parga, Greece</a></h6>
                                        <span class="time">5352+ tours & 856+ Activity</span>
                                        <a href="#" class="more"><i class="fas fa-chevron-right"></i></a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="destination-item style-two" data-aos="flip-up" data-aos-delay="200" data-aos-duration="1500" data-aos-offset="50">
                                    <div class="image">
                                        <a href="#" class="heart"><i class="fas fa-heart"></i></a>
                                        <img src="{{ asset('clients/assets/images/destinations/destination3.jpg') }}" alt="Destination">
                                    </div>
                                    <div class="content">
                                        <h6><a href="destination-details.html">Castellammare del Golfo, Italy</a></h6>
                                        <span class="time">5352+ tours & 856+ Activity</span>
                                        <a href="#" class="more"><i class="fas fa-chevron-right"></i></a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="destination-item style-two" data-aos="flip-up" data-aos-duration="1500" data-aos-offset="50">
                                    <div class="image">
                                        <a href="#" class="heart"><i class="fas fa-heart"></i></a>
                                        <img src="{{ asset('clients/assets/images/destinations/destination4.jpg') }}" alt="Destination">
                                    </div>
                                    <div class="content">
                                        <h6><a href="destination-details.html">Reserve of Canada, Canada</a></h6>
                                        <span class="time">5352+ tours & 856+ Activity</span>
                                        <a href="#" class="more"><i class="fas fa-chevron-right"></i></a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-md-6">
                                <div class="destination-item style-two" data-aos="flip-up" data-aos-delay="100" data-aos-duration="1500" data-aos-offset="50">
                                    <div class="image">
                                        <a href="#" class="heart"><i class="fas fa-heart"></i></a>
                                        <img src="{{ asset('clients/assets/images/destinations/destination5.jpg') }}" alt="Destination">
                                    </div>
                                    <div class="content">
                                        <h6><a href="destination-details.html">Dubai united states</a></h6>
                                        <span class="time">5352+ tours & 856+ Activity</span>
                                        <a href="#" class="more"><i class="fas fa-chevron-right"></i></a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-md-6">
                                <div class="destination-item style-two" data-aos="flip-up" data-aos-delay="200" data-aos-duration="1500" data-aos-offset="50">
                                    <div class="image">
                                        <a href="#" class="heart"><i class="fas fa-heart"></i></a>
                                        <img src="{{ asset('clients/assets/images/destinations/destination6.jpg') }}" alt="Destination">
                                    </div>
                                    <div class="content">
                                        <h6><a href="destination-details.html">Milos, Greece</a></h6>
                                        <span class="time">5352+ tours & 856+ Activity</span>
                                        <a href="#" class="more"><i class="fas fa-chevron-right"></i></a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <!-- Popular Destinations Area end -->
        
        
        <!-- Features Area start -->
        <section class="features-area pt-100 pb-45 rel z-1">
            <div class="container">
                <div class="row align-items-center">
                    <div class="col-xl-6">
                        <div class="features-content-part mb-55" data-aos="fade-left" data-aos-duration="1500" data-aos-offset="50">
                            <div class="section-title mb-60">
                                <h2>Trải nghiệm du lịch tuyệt vời</h2>
                            </div>
                            <div class="features-customer-box">
                                <div class="image">
                                    <img src="{{ asset('clients/assets/images/features/features-box.jpg') }}" alt="Features">
                                </div>
                                <div class="content">
                                    <div class="feature-authors mb-15">
                                        <img src="{{ asset('clients/assets/images/features/feature-author1.jpg') }}" alt="Author">
                                        <img src="{{ asset('clients/assets/images/features/feature-author2.jpg') }}" alt="Author">
                                        <img src="{{ asset('clients/assets/images/features/feature-author3.jpg') }}" alt="Author">
                                        <span>4k+</span>
                                    </div>
                                    <h6>850K+ Khách hàng hài lòng </h6>
                                    <div class="divider style-two counter-text-wrap my-25"><span><span class="count-text plus" data-speed="3000" data-stop="25">0</span> Years</span></div>
                                    <p>Chúng tôi tự hào cung cấp các hành trình cá nhân</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-6" data-aos="fade-right" data-aos-duration="1500" data-aos-offset="50">
                        <div class="row pb-25">
                            <div class="col-md-6">
                                <div class="feature-item">
                                    <div class="icon"><i class="flaticon-tent"></i></div>
                                    <div class="content">
                                        <h5><a href="{{route('tours')}}">Cắm trại bằng lều</a></h5>
                                        <p>Căm trại bằng lều là cách tuyệt vời nhất để kết nối với thiên nhiên</p>
                                    </div>
                                </div>
                                <div class="feature-item">
                                    <div class="icon"><i class="flaticon-tent"></i></div>
                                    <div class="content">
                                        <h5><a href="{{route('tours')}}">chèo kayak</a></h5>
                                        <p>chèo kayak là một hoạt động ngoài trời thú vị mang lại cảm giác hồi hộp</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="feature-item mt-20">
                                    <div class="icon"><i class="flaticon-tent"></i></div>
                                    <div class="content">
                                        <h5><a href="{{route('tours')}}">Đạp xe đồi</a></h5>
                                        <p>Đạp xe đồi là môn thể thao kích thích tinh thần giúp cải thiện sức khỏe</p>
                                    </div>
                                </div>
                                <div class="feature-item">
                                    <div class="icon"><i class="flaticon-tent"></i></div>
                                    <div class="content">
                                        <h5><a href="{{route('tours')}}">Câu cá</a></h5>
                                        <p>Câu cá và đi thuyền là những hoạt động mang lại niềm vui thiết yếu</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <!-- Features Area end -->
         
        <!-- CTA Area start -->
        <section class="cta-area pt-100 rel z-1">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-xl-4 col-md-6" data-aos="zoom-in-down" data-aos-duration="1500" data-aos-offset="50">
                        <div class="cta-item" style="background-image: url({{ asset('clients/assets/images/cta/cta1.jpg') }});">
                            <span class="category">Cắm trại bằng lều</span>
                            <h2>Khám phá du lịch tốt nhất Việt Nam</h2>
                            <a href="{{route('tours')}}" class="theme-btn style-two bgc-secondary">
                                <span data-hover="Khám phá ">Khám phá </span>
                                <i class="fal fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6" data-aos="zoom-in-down" data-aos-delay="50" data-aos-duration="1500" data-aos-offset="50">
                        <div class="cta-item" style="background-image: url({{ asset('clients/assets/images/cta/cta2.jpg') }});">
                            <span class="category">Sea Beach</span>
                            <h2>World largest Sea Beach in Thailand</h2>
                            <a href="{{route('tours')}}" class="theme-btn style-two">
                                <span data-hover="Khám phá ">Khám phá </span>
                                <i class="fal fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6" data-aos="zoom-in-down" data-aos-delay="100" data-aos-duration="1500" data-aos-offset="50">
                        <div class="cta-item" style="background-image: url({{ asset('clients/assets/images/cta/cta3.jpg') }});">
                            <span class="category">Water Falls</span>
                            <h2>Largest Water falls Bali, Indonesia</h2>
                            <a href="{{route('tours')}}" class="theme-btn style-two bgc-secondary">
                                <span data-hover="Khám phá ">Khám phá </span>
                                <i class="fal fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <!-- CTA Area end -->
         
         
        <!-- Blog Area start -->
        <section class="blog-area py-70 rel z-1">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-lg-12">
                        <div class="section-title text-center counter-text-wrap mb-70" data-aos="fade-up" data-aos-duration="1500" data-aos-offset="50">
                            <h2>Đọc tin tức mới nhất</h2>
                            <p>Website <span class="count-text plus bgc-primary" data-speed="3000" data-stop="34500">0</span>trải nghiệm phổ biến nhất mà bạn sẽ nhớ</p>
                        </div>
                    </div>
                </div>
                <div class="row justify-content-center">
                    <div class="col-xl-4 col-md-6">
                        <div class="blog-item" data-aos="fade-up" data-aos-duration="1500" data-aos-offset="50">
                            <div class="content">
                                <a href="blog.html" class="category">Travel</a>
                                <h5><a href="blog-details.html">Ultimate Guide to Planning Your Dream Vacation with Travela Travel Agency</a></h5>
                                <ul class="blog-meta">
                                    <li><i class="far fa-calendar-alt"></i> <a href="#">25 February 2026</a></li>
                                    <li><i class="far fa-comments"></i> <a href="#">Comments (5)</a></li>
                                </ul>
                            </div>
                            <div class="image">
                                <img src="{{ asset('clients/assets/images/blog/blog1.jpg') }}" alt="Blog">
                            </div>
                            <a href="blog-details.html" class="theme-btn">
                                <span data-hover="Book Now">Read More</span>
                                <i class="fal fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6">
                        <div class="blog-item" data-aos="fade-up" data-aos-delay="50" data-aos-duration="1500" data-aos-offset="50">
                            <div class="content">
                                <a href="blog.html" class="category">Travel</a>
                                <h5><a href="blog-details.html">Unforgettable Adventures Travel Agency Bucket List Experiences</a></h5>
                                <ul class="blog-meta">
                                    <li><i class="far fa-calendar-alt"></i> <a href="#">25 February 2024</a></li>
                                    <li><i class="far fa-comments"></i> <a href="#">Comments (5)</a></li>
                                </ul>
                            </div>
                            <div class="image">
                                <img src="{{ asset('clients/assets/images/blog/blog2.jpg') }}" alt="Blog">
                            </div>
                            <a href="blog-details.html" class="theme-btn">
                                <span data-hover="Book Now">Read More</span>
                                <i class="fal fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6">
                        <div class="blog-item" data-aos="fade-up" data-aos-delay="100" data-aos-duration="1500" data-aos-offset="50">
                            <div class="content">
                                <a href="blog.html" class="category">Travel</a>
                                <h5><a href="blog-details.html">Exploring Culture and way Cuisine Travel Agency's they Best Foodie Destinations</a></h5>
                                <ul class="blog-meta">
                                    <li><i class="far fa-calendar-alt"></i> <a href="#">25 February 2024</a></li>
                                    <li><i class="far fa-comments"></i> <a href="#">Comments (5)</a></li>
                                </ul>
                            </div>
                            <div class="image">
                                <img src="{{ asset('clients/assets/images/blog/blog3.jpg') }}" alt="Blog">
                            </div>
                            <a href="blog-details.html" class="theme-btn">
                                <span data-hover="Book Now">Read More</span>
                                <i class="fal fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <!-- Blog Area end -->
          
           
@include('clients.blocks.footer_home')