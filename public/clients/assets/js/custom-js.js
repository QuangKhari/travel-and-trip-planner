$(document).ready(function () {
    var sqlInjectionPattern = /[<>'"%;()&+]/;

    /****************************************
     *              PAGE LOGIN              *
     * ***************************************/
    //Handle click switch tab
    $("#sign-up").click(function () {
        console.log("111");
        $(".sign-in").hide();
        $(".signup").show();
    });
    $("#sign-in").click(function () {
        console.log("222");
        $(".signup").hide();
        $(".sign-in").show();
    });

    // Handle form submission for signin
    $("#login-form").on("submit", function (e) {
        e.preventDefault();
        var userName = $("#username_login").val().trim();
        var password = $("#password_login").val().trim();

        // Đặt lại nội dung thông báo lỗi và ẩn chúng
        $("#validate_username").hide().text("");
        $("#validate_password").hide().text("");

        var isValid = true;

        // Kiểm tra độ dài mật khẩu
        if (password.length < 6) {
            isValid = false;
            $("#validate_password")
                .show()
                .text("Mật khẩu phải có ít nhất 6 ký tự.");
        }

        // Kiểm tra tên đăng nhập và mật khẩu không chứa ký tự đặc biệt (SQL injection)

        if (sqlInjectionPattern.test(userName)) {
            isValid = false;
            $("#validate_username")
                .show()
                .text("Tên đăng nhập không được chứa ký tự đặc biệt.");
        }

        if (isValid) {
            var formData = {
                username: userName,
                password: password,
                _token: $('input[name="_token"]').val(),
            };

            $.ajax({
                type: "POST",
                url: $(this).attr("action"),
                data: formData,
                success: function (response) {
                    if (response.success) {
                        window.location.href = response.redirectUrl || "/";
                    } else {
                        toastr.error(response.message);
                    }
                },
                error: function (xhr, textStatus, errorThrown) {
                    if (xhr.status === 429) {
                        toastr.error(
                            "Bạn thử quá nhiều lần. Vui lòng đợi một phút rồi thử lại.",
                        );
                        return;
                    }
                    toastr.error("Có lỗi xảy ra. Vui lòng thử lại sau.");
                },
            });
        }
    });

    // Handle form submission for signup
    $("#register-form").on("submit", function (e) {
        e.preventDefault();
        $(".loader").show();
        //$("#register-form").addClass("hidden-content");

        // Lấy giá trị của các trường nhập liệu
        var userName = $("#username_register").val().trim();
        var email = $("#email_register").val().trim();
        var password = $("#password_register").val().trim();
        var rePass = $("#re_pass").val().trim();

        // Đặt lại nội dung thông báo lỗi và ẩn chúng
        $("#validate_username_regis").hide().text("");
        $("#validate_email_regis").hide().text("");
        $("#validate_password_regis").hide().text("");
        $("#validate_repass").hide().text("");

        // Kiểm tra lỗi
        var isValid = true;

        // Kiểm tra tên đăng nhập không chứa ký tự SQL injection
        if (sqlInjectionPattern.test(userName)) {
            isValid = false;
            $("#validate_username_regis")
                .show()
                .text("Tên tài khoản không được chứa ký tự đặc biệt.");
        }

        var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailPattern.test(email)) {
            isValid = false;
            $("#validate_email_regis").show().text("Email không hợp lệ.");
        }

        if (password.length < 8) {
            isValid = false;
            $("#validate_password_regis")
                .show()
                .text("Mật khẩu phải có ít nhất 8 ký tự.");
        }

        // Kiểm tra nhập lại mật khẩu
        if (password !== rePass) {
            isValid = false;
            $("#validate_repass").show().text("Mật khẩu nhập lại không khớp.");
        }

        if (isValid) {
            var formData = {
                username_regis: userName,
                email: email,
                password_regis: password,
                _token: $('input[name="_token"]').val(),
            };

            $.ajax({
                type: "POST",
                url: $(this).attr("action"),
                data: formData,
                success: function (response) {
                    if (response.success) {
                        toastr.success(response.message, { timeOut: 5000 });
                        $("#register-form")
                            .removeClass("hidden-content")
                            .trigger("reset");
                    } else {
                        toastr.error(response.message);
                        $("#register-form").removeClass("hidden-content");
                    }
                    $(".loader").hide();
                },
                error: function (xhr, textStatus, errorThrown) {
                    $(".loader").hide();
                    toastr.error("Có lỗi xảy ra. Vui lòng thử lại sau.");
                },
            });
        }
    });

    $("#resend-verification-link").on("click", function () {
        $("#resend-verification-form").slideToggle();
    });

    $("#resend-verification-button").on("click", function () {
        var email = $("#resend_verification_email").val().trim();

        if (!email) {
            toastr.error("Vui lòng nhập email.");
            return;
        }

        $.ajax({
            type: "POST",
            url: window.emailVerificationResendUrl,
            data: {
                email: email,
                _token: $('input[name="_token"]').first().val(),
            },
            success: function (response) {
                if (response.success) {
                    toastr.success(response.message, { timeOut: 5000 });
                } else {
                    toastr.error(response.message);
                }
            },
            error: function () {
                toastr.error("Có lỗi xảy ra. Vui lòng thử lại sau.");
            },
        });
    });

    /****************************************
     *              HOME PAGE             *
     * ***************************************/
    $("#start_date, #end_date").datetimepicker({
        format: "d/m/Y",
        timepicker: false,
    });
    /****************************************
     *              HEADER                  *
     * ***************************************/
    $("#userDropdown").click(function (e) {
        e.stopPropagation();
        $("#dropdownMenu").toggle();
    });

    // Đóng dropdown khi click ra ngoài
    $(document).click(function (e) {
        if (!$(e.target).closest(".menu-sidebar").length) {
            $("#dropdownMenu").hide();
        }
    });

    $("#dropdownMenu a").on("click", function (e) {
        e.stopPropagation(); // ngăn bubble lên document
        var href = $(this).attr("href");
        if (href && href !== "#") {
            window.location.href = href;
        }
    });

    // Form đăng xuất: không cho click nổi bọt lên .menu-sidebar
    // (script.js gọi preventDefault ở đó nên form không được gửi)
    $("#dropdownMenu form").on("click", function (e) {
        e.stopPropagation();
    });

    /****************************************
     *              PAGE TOURS              *
     * ***************************************/
    // Kiểm tra nếu thanh trượt đã tồn tại
    if ($(".price-slider-range").length) {
        $(".price-slider-range").on("slidestop", function (event, ui) {
            filterTours(ui.values[0], ui.values[1]);
        });
    }
    let filterTimeout;

    function debounceFilter() {
        clearTimeout(filterTimeout);

        filterTimeout = setTimeout(() => {
            filterTours();
        }, 300);
    }

    $('input[name="domain"]').on("change", debounceFilter);
    $('input[name="filter_star"]').on("change", debounceFilter);
    $('input[name="duration"]').on("change", debounceFilter);

    $("#sorting_tours").on("change", function () {
        filterTours(null, null);
    });

    function filterTours(minPrice = null, maxPrice = null) {
        $(".loader").show();
        $("#tours-container").addClass("hidden-content");

        if (minPrice === null || maxPrice === null) {
            minPrice = $(".price-slider-range").slider("values", 0);
            maxPrice = $(".price-slider-range").slider("values", 1);
        }

        var domain = $('input[name="domain"]:checked').val();
        var star = $('input[name="filter_star"]:checked').val();
        var duration = $('input[name="duration"]:checked').val();
        var sorting = $("#sorting_tours").val();

        formDataFilter = {
            minPrice: minPrice,
            maxPrice: maxPrice,
            domain: domain,
            star: star,
            time: duration,
            sorting: sorting,
        };
        console.log(formDataFilter);

        $.ajax({
            url: filterToursUrl,
            method: "GET",
            data: formDataFilter,
            success: function (res) {
                $("#tours-container").html(res).removeClass("hidden-content");
                $("#tours-container .destination-item").addClass("aos-animate");
                $(".loader").hide();
            },
        });
    }

    $(document).on("click", ".pagination-tours a", function (e) {
        e.preventDefault();
        $(".loader").show();
        $("#tours-container").addClass("hidden-content");

        var url = $(this).attr("href");
        console.log(url);

        var domain = $('input[name="domain"]:checked').val();
        var star = $('input[name="filter_star"]:checked').val();
        var duration = $('input[name="duration"]:checked').val();
        var sorting = $("#sorting_tours").val();

        var minPrice = $(".price-slider-range").slider("values", 0);
        var maxPrice = $(".price-slider-range").slider("values", 1);

        $.ajax({
            url: url,
            type: "GET",
            data: {
                minPrice: minPrice,
                maxPrice: maxPrice,
                domain: domain,
                star: star,
                time: duration,
                sorting: sorting,
            },

            success: function (response) {
                // Cập nhật toàn bộ nội dung (tours và phân trang)
                $("#tours-container")
                    .html(response)
                    .removeClass("hidden-content");
                $("#tours-container .destination-item").addClass("aos-animate");
                $(".loader").hide();
            },
            error: function (xhr, status, error) {
                $(".loader").hide();
                console.log("Có lỗi xảy ra trong quá trình tải dữ liệu!");
            },
        });
    });

    // Hàm để clear các filter đã chọn
    $(".clear_filter a").on("click", function (e) {
        e.preventDefault();

        // reset slider
        $(".price-slider-range").slider("values", [0, 20000000]);

        // bỏ chọn filter
        $('input[name="domain"]').prop("checked", false);
        $('input[name="filter_star"]').prop("checked", false);
        $('input[name="duration"]').prop("checked", false);

        // reset sorting
        $("#sorting_tours").val("default");

        // gọi lại filter
        filterTours(0, 20000000);

        // Bỏ chọn radio và checkbox
        $('input[name="domain"]').prop("checked", false);
        $('input[name="filter_star"]').prop("checked", false);
        $('input[name="duration"]').prop("checked", false);

        var url = $(this).attr("href");

        $.ajax({
            url: url,
            type: "GET",
            dataType: "json",
            success: function (response) {
                // Cập nhật toàn bộ nội dung (tours và phân trang)
                $("#tours-container")
                    .html(response.tours)
                    .removeClass("hidden-content");
                $("#tours-container .destination-item").addClass("aos-animate");
                $("#tours-container .pagination-tours").addClass("aos-animate");
                $(".loader").hide();
            },
            error: function (xhr, status, error) {
                console.log("Có lỗi xảy ra trong quá trình tải dữ liệu!");
            },
        });
    });

    /****************************************
     *             PAGE TOURDETAIL          *
     * ***************************************/

    let currentRating = 0;

    $("#rating-stars i").on("mouseover", function () {
        let rating = $(this).data("value");
        highlightStars(rating);
    });

    $("#rating-stars i").on("click", function () {
        currentRating = $(this).data("value");
        console.log("Sao đã chọn :", currentRating);
    });

    $("#rating-stars i").on("mouseout", function () {
        resetStars();
        if (currentRating > 0) {
            highlightStars(currentRating);
        }
    });

    // Hàm tô màu các sao được chọn
    function highlightStars(rating) {
        $("#rating-stars i").each(function () {
            if ($(this).data("value") <= rating) {
                $(this).removeClass("far").addClass("fas active");
            } else {
                $(this).removeClass("fas active").addClass("far");
            }
        });
    }

    // Hàm đặt lại tất cả sao về trạng thái chưa chọn
    function resetStars() {
        $("#rating-stars i").each(function () {
            $(this).removeClass("fas active").addClass("far");
        });
    }
    let urlCheckBooking = $("#submit-reviews").attr("data-url-checkBooking");
    let urlSubmitReview = $("#comment-form").attr("action");
    let tourIdReview = $("#submit-reviews").attr("data-tourId-reviews");

    $("#comment-form").on("submit", function (e) {
        e.preventDefault();

        let message = $("#message").val().trim();

        // Kiểm tra số sao và nội dung
        if (currentRating === 0) {
            toastr.warning("Vui lòng chọn số sao để đánh giá.");
            return;
        } else if (message === "") {
            toastr.warning("Vui lòng nhập nội dung phản hồi.");
            return;
        }

        $.ajax({
            url: urlCheckBooking,
            method: "POST",
            data: {
                tourId: tourIdReview,
                _token: $('input[name="_token"]').val(),
            },
            success: function (response) {
                if (response.success) {
                    formReviews = {
                        tourId: tourIdReview,
                        rating: currentRating,
                        message: message,
                        _token: $('input[name="_token"]').val(),
                    };

                    // Gửi AJAX request
                    $.ajax({
                        url: urlSubmitReview, // Lấy URL từ action của form
                        method: "POST",
                        data: formReviews,
                        success: function (response) {
                            if (response.success) {
                                toastr.success(response.message);
                                $("#partials_reviews").html(response.data);
                                $("#partials_reviews .comment-body").addClass(
                                    "aos-animate",
                                );
                                // Xử lý reset form hoặc thông báo
                                $("#message").val("");
                                $("#comment-form").hide();
                                resetStars();
                                currentRating = 0;
                            }
                        },
                        error: function (xhr, status, error) {
                            toastr.error("Đã có lỗi xảy ra. Vui lòng thử lại.");
                            console.error("Error:", error);
                        },
                    });
                } else {
                    toastr.error(
                        "Vui lòng đặt tour và trải nghiệm để có thể đánh giá!",
                    );
                }
            },
            error: function (xhr, status, error) {
                toastr.error("Đã có lỗi xảy ra. Vui lòng thử lại.");
                console.error("Error:", error);
            },
        });
    });
    /****************************************
     *             HANDLE SEARCH            *
     * ***************************************/

    $("#search_form").on("submit", function (event) {
        // Lấy giá trị các trường cần kiểm tra
        var destination = $("#destination").val();
        var startDate = $("#start_date").val();
        var endDate = $("#end_date").val();

        if (destination === "") {
            event.preventDefault();
            toastr.error("Vui lòng chọn điểm đến.");
            return;
        }

        // Chuyển đổi định dạng ngày từ DD/MM/YYYY sang YYYY-MM-DD
        function convertDateFormat(date) {
            var parts = date.split("/");
            return parts[2] + "-" + parts[1] + "-" + parts[0];
        }

        if (startDate && endDate) {
            var startDateFormatted = new Date(convertDateFormat(startDate));
            var endDateFormatted = new Date(convertDateFormat(endDate));

            // Kiểm tra nếu "start_date" lớn hơn "end_date"
            if (startDateFormatted > endDateFormatted) {
                event.preventDefault();
                toastr.error("Ngày khởi hành không thể lớn hơn ngày kết thúc.");
                return;
            }
        }
    });

    /****************************************
     *  HANDLE SEARCH Speech Recognition    *
     * ***************************************/

    // Kiểm tra nếu trình duyệt hỗ trợ Speech Recognition
    if ("SpeechRecognition" in window || "webkitSpeechRecognition" in window) {
        var recognition = new (
            window.SpeechRecognition || window.webkitSpeechRecognition
        )();
        recognition.lang = "vi-VN"; // Cài đặt ngôn ngữ nhận diện
        recognition.continuous = true; // Tiếp tục nhận diện khi đang nói
        recognition.interimResults = true; // Hiển thị kết quả tạm thời khi nhận diện

        // Biến để theo dõi trạng thái nhận diện
        var isRecognizing = false;

        $("#voice-search").on("click", function () {
            if (isRecognizing) {
                recognition.stop(); // Dừng nhận diện nếu đang nhận diện
                $(this)
                    .removeClass("fa-microphone-slash")
                    .addClass("fa-microphone"); // Đổi icon về micro
            } else {
                recognition.start(); // Bắt đầu nhận diện giọng nói
                $(this)
                    .removeClass("fa-microphone")
                    .addClass("fa-microphone-slash"); // Đổi icon thành micro gạch
            }
        });

        recognition.onstart = function () {
            console.log("Speech recognition started");
            isRecognizing = true; // Đánh dấu trạng thái nhận diện
            $("#voice-search")
                .removeClass("fa-microphone")
                .addClass("fa-microphone-slash"); // Đổi icon thành micro gạch
        };

        recognition.onresult = function (event) {
            var result = event.results[event.resultIndex][0];
            var transcript = result.transcript.trim();

            $('input[name="keyword"]').val(transcript);
        };

        recognition.onerror = function (event) {
            console.log("Speech recognition error", event.error);
            toastr.error(
                "Có lỗi xảy ra khi nhận diện giọng nói: " + event.error,
            );
        };

        recognition.onend = function () {
            console.log("Speech recognition ended");
            $("#voice-search")
                .removeClass("fa-microphone-slash")
                .addClass("fa-microphone"); // Đổi icon về micro
            isRecognizing = false; // Đánh dấu trạng thái nhận diện kết thúc
        };
    } else {
        console.log("Speech recognition not supported in this browser.");
        toastr.error("Trình duyệt của bạn không hỗ trợ nhận diện giọng nói.");
    }
});
