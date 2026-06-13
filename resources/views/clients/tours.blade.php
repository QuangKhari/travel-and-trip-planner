@include('clients.blocks.header')
@include('clients.blocks.banner')

<!-- Tour Grid Area start -->
        <section class="tour-grid-page py-100 rel z-1">
            <div class="container">
                <div class="row">
                    <div class="col-lg-3 col-md-6 col-sm-10 rmb-75">
                        <div class="shop-sidebar">
                            <div class="div_filter_clear">
                                <button class="clear_filter" name="btn_clear">
                                    <a href="{{ route('tours') }}">Clear</a>
                                </button>
                            </div>
                            <div class="widget widget-filter" data-aos="fade-up" data-aos-delay="50" data-aos-duration="1500" data-aos-offset="50">
                                <h6 class="widget-title">Lọc theo giá </h6>
                                <div class="price-filter-wrap">
                                    <div class="price-slider-range"></div>
                                    <div class="price">
                                        <span>Giá </span>
                                        <input type="text" id="price" readonly>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="widget widget-activity" data-aos="fade-up" data-aos-duration="1500"
                                data-aos-offset="50">
                                <h6 class="widget-title">Điểm đến</h6>
                                <ul class="radio-filter">
                                    <li>
                                        <input class="form-check-input" type="radio" name="domain" id="id_mien_bac"
                                            value="b">
                                        <label for="id_mien_bac">Miền Bắc <span>{{ $domainsCount['mien_bac'] }}</span></label>
                                    </li>
                                    <li>
                                        <input class="form-check-input" type="radio" name="domain" id="id_mien_trung"
                                            value="t">
                                        <label for="id_mien_trung">Miền Trung
                                            <span>{{ $domainsCount['mien_trung'] }}</span></label>
                                    </li>
                                    <li>
                                        <input class="form-check-input" type="radio" name="domain" id="id_mien_nam"
                                            value="n">
                                        <label for="id_mien_nam">Miền Nam <span>{{ $domainsCount['mien_nam'] }}</span></label>
                                    </li>
                                </ul>
                            </div>
                            
                            <div class="widget widget-reviews" data-aos="fade-up" data-aos-duration="1500" data-aos-offset="50">
                        <h6 class="widget-title">Đánh giá</h6>
                        <ul class="radio-filter">
                            <li>
                                <input class="form-check-input" type="radio" name="filter_star" id="5star"
                                    value="5">
                                <label for="5star">
                                    <span class="ratting">
                                        <i class="fas fa-star"></i>
                                        <i class="fas fa-star"></i>
                                        <i class="fas fa-star"></i>
                                        <i class="fas fa-star"></i>
                                        <i class="fas fa-star"></i>
                                    </span>
                                </label>
                            </li>
                            <li>
                                <input class="form-check-input" type="radio" name="filter_star" id="4star"
                                    value="4">
                                <label for="4star">
                                    <span class="ratting">
                                        <i class="fas fa-star"></i>
                                        <i class="fas fa-star"></i>
                                        <i class="fas fa-star"></i>
                                        <i class="fas fa-star"></i>
                                        <i class="fas fa-star-half-alt white"></i>
                                    </span>
                                </label>
                            </li>
                            <li>
                                <input class="form-check-input" type="radio" name="filter_star" id="3star"
                                    value="3">
                                <label for="3star">
                                    <span class="ratting">
                                        <i class="fas fa-star"></i>
                                        <i class="fas fa-star"></i>
                                        <i class="fas fa-star"></i>
                                        <i class="fas fa-star white"></i>
                                        <i class="fas fa-star-half-alt white"></i>
                                    </span>
                                </label>
                            </li>
                            <li>
                                <input class="form-check-input" type="radio" name="filter_star" id="2star"
                                    value="2">
                                <label for="2star">
                                    <span class="ratting">
                                        <i class="fas fa-star"></i>
                                        <i class="fas fa-star"></i>
                                        <i class="fas fa-star white"></i>
                                        <i class="fas fa-star white"></i>
                                        <i class="fas fa-star-half-alt white"></i>
                                    </span>
                                </label>
                            </li>
                            <li>
                                <input class="form-check-input" type="radio" name="filter_star" id="1star"
                                    value="1">
                                <label for="1star">
                                    <span class="ratting">
                                        <i class="fas fa-star"></i>
                                        <i class="fas fa-star white"></i>
                                        <i class="fas fa-star white"></i>
                                        <i class="fas fa-star white"></i>
                                        <i class="fas fa-star-half-alt white"></i>
                                    </span>
                                </label>
                            </li>
                        </ul>
                    </div>
                            <div class="widget widget-duration" data-aos="fade-up" data-aos-duration="1500" data-aos-offset="50">
                                <h6 class="widget-title">Thời gian</h6>
                                <ul class="radio-filter">
                            <li>
                                <input class="form-check-input" type="radio" name="duration" id="3ngay2dem"
                                    value="3n2d">
                                <label for="3ngay2dem">3 ngày 2 đêm</label>
                            </li>
                            <li>
                                <input class="form-check-input" type="radio" name="duration" id="4ngay3dem"
                                    value="4n3d">
                                <label for="4ngay3dem">4 ngày 3 đêm</label>
                            </li>
                            <li>
                                <input class="form-check-input" type="radio" name="duration" id="6ngay5dem"
                                    value="6n5d">
                                <label for="6ngay5dem">6 ngày 5 đêm</label>
                            </li>
                        </ul>
                            </div>
                            
                            <div class="widget widget-tour" data-aos="fade-up" data-aos-duration="1500" data-aos-offset="50">
                            <h6 class="widget-title">Tours Phổ Biến</h6>

                            @forelse ($popularTours as $popular)
                                <div class="destination-item tour-grid style-three bgc-lighter mb-15">
                                <div class="image">
                                    <span class="badge">
                                        {{ number_format($popular->averageRating, 1) }} ⭐
                                    </span>
                                    @if ($popular->thumbnail !== 'default.jpg')
                                        <img src="{{ asset('admin/assets/images/gallery-tours/' . $popular->thumbnail) }}"
                                        alt="{{ $popular->title }}"
                                        style="width: 100%; height: 80px; object-fit: cover;">
                                    @else
                                        <img src="{{ asset('clients/assets/images/widgets/tour1.jpg') }}"
                                        alt="Tour">
                                    @endif
                                </div>
                                <div class="content">
                                    <div class="destination-header">
                                        <span class="location">
                                            <i class="fal fa-map-marker-alt"></i> 
                                            {{ $popular->destination }}
                                        </span>
                                        <div class="ratting">
                                            <i class="fas fa-star"></i>
                                            <span>({{ number_format($popular->averageRating, 1) }})</span>
                                            </div>
                                        </div>
                                        <h6>
                                        <a href="{{ route('tour-detail', $popular->tourId) }}">
                                            {{ Str::limit($popular->title, 40) }}
                                        </a>
                                </h6>
                            </div>
                        </div>
                            @empty
                                <p>Chưa có tour phổ biến</p>
                            @endforelse
                        </div>
                        </div>
                        
                        <div class="widget widget-cta mt-30" data-aos="fade-up" data-aos-duration="1500" data-aos-offset="50">
                            <div class="content text-white">
                                <span class="h6">Explore The World</span>
                                <h3>Best Tourist Place</h3>
                                <a href="tour-list.html" class="theme-btn style-two bgc-secondary">
                                    <span data-hover="Explore Now">Explore Now</span>
                                    <i class="fal fa-arrow-right"></i>
                                </a>
                            </div>
                            <div class="image">
                                <img src="{{ asset('clients/assets/images/widgets/cta-widget.png')}}" alt="CTA">
                            </div>
                            <div class="cta-shape"><img src="{{ asset('clients/assets/images/widgets/cta-shape2.png')}}" alt="Shape"></div>
                        </div>
                    </div>
                    <div class="col-lg-9">
                        <div class="shop-shorter rel z-3 mb-20">
                            <div class="sort-text mb-15 me-4 me-xl-auto">
                                Tours tìm thấy:
                            </div>
                            <div class="sort-text mb-15 me-4">
                                Sort By
                            </div>
                            <select id="sorting_tours">
                                <option value="default" selected="">sắp xếp theo</option>
                                <option value="new">mới nhất</option>
                                <option value="old">cũ nhất</option>
                                <option value="high-to-low">cao đến thấp</option>
                                <option value="low-to-high">thấp đến cao</option>
                            </select>
                        </div>
                        
                        <div class="tour-grid-wrap">
                            <div class="loader"></div>
                            <div class="row" id="tours-container">
                                @include('clients.partials.filter-tours')

                            </div>
                        </div>
                        
                    </div>
                </div>
            </div>
        </section>
        <!-- Tour Grid Area end -->
        

@include('clients.blocks.new_letter')

<!-- footer area start -->
        <footer class="main-footer footer-two bgp-bottom bgc-black rel z-15 pt-100 pb-115" style="background-image: url({{ asset('clients/assets/images/backgrounds/footer-two.png') }});">
            <div class="widget-area">
                <div class="container">
                    <div class="row row-cols-xxl-5 row-cols-xl-4 row-cols-md-3 row-cols-2">
                        <div class="col col-small" data-aos="fade-up" data-aos-duration="1500" data-aos-offset="50">
                            <div class="footer-widget footer-text">
                                <div class="footer-logo mb-40">
                                    <a href="index.html"><img src="{{ asset('clients/assets/images/logos/logo.png')}}" alt="Logo"></a>
                                </div>
                                <div class="footer-map">
                                    <iframe src="https://www.google.com/maps/embed?pb=!1m10!1m8!1m3!1d96777.16150026117!2d-74.00840582560909!3d40.71171357405996!3m2!1i1024!2i768!4f13.1!5e0!3m2!1sen!2sbd!4v1706508986625!5m2!1sen!2sbd" style="border:0; width: 100%;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                                </div>
                            </div>
                        </div>
                        <div class="col col-small" data-aos="fade-up" data-aos-delay="50" data-aos-duration="1500" data-aos-offset="50">
                            <div class="footer-widget footer-links ms-sm-5">
                                <div class="footer-title">
                                    <h5>Services</h5>
                                </div>
                                <ul class="list-style-three">
                                    <li><a href="destination-details.html">Best Tour Guide</a></li>
                                    <li><a href="destination-details.html">Tour Booking</a></li>
                                    <li><a href="destination-details.html">Hotel Booking</a></li>
                                    <li><a href="destination-details.html">Ticket Booking</a></li>
                                </ul>
                            </div>
                        </div>
                        <div class="col col-small" data-aos="fade-up" data-aos-delay="100" data-aos-duration="1500" data-aos-offset="50">
                            <div class="footer-widget footer-links ms-md-4">
                                <div class="footer-title">
                                    <h5>Company</h5>
                                </div>
                                <ul class="list-style-three">
                                    <li><a href="about.html">About Company</a></li>
                                    <li><a href="blog.html">Community Blog</a></li>
                                    <li><a href="contact.html">Jobs and Careers</a></li>
                                    <li><a href="blog.html">latest News Blog</a></li>
                                </ul>
                            </div>
                        </div>
                        <div class="col col-small" data-aos="fade-up" data-aos-delay="150" data-aos-duration="1500" data-aos-offset="50">
                            <div class="footer-widget footer-links ms-lg-4">
                                <div class="footer-title">
                                    <h5>Destinations</h5>
                                </div>
                                <ul class="list-style-three">
                                    <li><a href="destination-details.html">African Safaris</a></li>
                                    <li><a href="destination-details.html">Alaska & Canada</a></li>
                                    <li><a href="destination-details.html">South America</a></li>
                                    <li><a href="destination-details.html">Middle East</a></li>
                                </ul>
                            </div>
                        </div>
                        <div class="col col-md-6 col-10 col-small" data-aos="fade-up" data-aos-delay="200" data-aos-duration="1500" data-aos-offset="50">
                            <div class="footer-widget footer-contact">
                                <div class="footer-title">
                                    <h5>Get In Touch</h5>
                                </div>
                                <ul class="list-style-one">
                                    <li><i class="fal fa-map-marked-alt"></i> 71 Giari Phóng, Hai Bà Trưng, Hà Nội</li>
                                    <li><i class="fal fa-envelope"></i> <a href="mailto:supportravelo@gmail.com">supportravelo @gmail.com</a></li>
                                    <li><i class="fal fa-phone-volume"></i> <a href="callto:+00000000000">+00000000000</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="footer-bottom bg-transparent pt-20 pb-5">
                <div class="container">
                    <div class="row">
                       <div class="col-lg-5">
                            <div class="copyright-text text-center text-lg-start">
                                <p>@Copy 2026 <a href="index.html">Travela</a>, All rights reserved</p>
                            </div>
                       </div>
                       <div class="col-lg-7 text-center text-lg-end">
                           <ul class="footer-bottom-nav">
                               <li><a href="about.html">Terms</a></li>
                               <li><a href="about.html">Privacy Policy</a></li>
                               <li><a href="about.html">Legal notice</a></li>
                               <li><a href="about.html">Accessibility</a></li>
                           </ul>
                       </div>
                    </div>
                </div>
            </div>
        </footer>
        <!-- footer area end -->

    </div>
    <!--End pagewrapper-->
   
    
    <!-- Jquery -->
    <script src="{{ asset('clients/assets/js/jquery-3.6.0.min.js')}}"></script>
    <!-- Bootstrap -->
    <script src="{{ asset('clients/assets/js/bootstrap.min.js')}}"></script>
    <!-- Appear Js -->
    <script src="{{ asset('clients/assets/js/appear.min.js')}}"></script>
    <!-- Slick -->
    <script src="{{ asset('clients/assets/js/slick.min.js')}}"></script>
    <!-- Magnific Popup -->
    <script src="{{ asset('clients/assets/js/jquery.magnific-popup.min.js')}}"></script>
    <!-- Nice Select -->
    <script src="{{ asset('clients/assets/js/jquery.nice-select.min.js')}}"></script>
    <!-- Image Loader -->
    <script src="{{ asset('clients/assets/js/imagesloaded.pkgd.min.js')}}"></script>
    <!-- Jquery UI -->
    <script src="{{ asset('clients/assets/js/jquery-ui.min.js')}}"></script>
    <!-- Isotope -->
    <script src="{{ asset('clients/assets/js/isotope.pkgd.min.js')}}"></script>
    <!--  AOS Animation -->
    <script src="{{ asset('clients/assets/js/aos.js')}}"></script>
    <!-- Custom script -->
    <script src="{{ asset('clients/assets/js/script.js')}}"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

    <script src="{{ asset('clients/assets/js/jquery.datetimepicker.full.min.js') }}"></script>

    <script src="{{ asset('clients/assets/js/custom-js.js')}}"></script>

</body>

<!-- Mirrored from webtendtheme.net/html/2024/ravelo/tour-sidebar.html by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 07 Oct 2024 09:28:07 GMT -->
</html>


<script>
    var filterToursUrl = "{{ route('filter-tours') }}";
</script>