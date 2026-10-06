$(document).ready(function () {
    /********************************************
     * USER MANAGEMENT                          *
     ********************************************/
    $("#btn-active").click(function () {
        var button = $(this);
        let dataAttr = button.data("attr");

        let userId = dataAttr.userId;
        let actionUrl = dataAttr.action;

        let formData = {
            userId: userId,
            _token: $('meta[name="csrf-token"]').attr("content"),
        };

        $.ajax({
            type: "POST",
            url: actionUrl,
            data: formData,
            success: function (response) {
                if (response.success) {
                    button
                        .closest(".profile_view")
                        .find(".brief i")
                        .text("Đã kích hoạt");
                    button.hide();
                    toastr.success(response.message);
                } else {
                    toastr.error(response.message);
                }
            },
            error: function (xhr, textStatus, errorThrown) {
                toastr.error("Có lỗi xảy ra. Vui lòng thử lại sau.");
            },
        });
    });

    $("#btn-ban, #btn-delete, #btn-unban, #btn-restore").click(function () {
        var button = $(this);
        let dataAttr = button.data("attr");

        let userId = dataAttr.userId;
        let status = dataAttr.status;
        let actionUrl = dataAttr.action;

        let formData = {
            userId: userId,
            status: status,
            _token: $('meta[name="csrf-token"]').attr("content"),
        };
        console.log(formData);

        $.ajax({
            type: "POST",
            url: actionUrl,
            data: formData,
            success: function (response) {
                if (response.success) {
                    button
                        .closest(".profile_view")
                        .find(".brief i")
                        .text(response.status);
                    button.parent().find("button").hide(); // Ẩn tất cả các nút
                    if (status === "b") {
                        button.parent().find("#btn-unban").show();
                    } else if (status === "d") {
                        button.parent().find("#btn-restore").show();
                    } else {
                        button
                            .parent()
                            .find("#btn-ban, #btn-delete, #btn-active")
                            .show();
                    }
                    toastr.success(response.message);
                } else {
                    toastr.error(response.message);
                }
            },
            error: function (xhr, textStatus, errorThrown) {
                toastr.error("Có lỗi xảy ra. Vui lòng thử lại sau.");
            },
        });
    });

    /********************************************
     * TOURS MANAGEMENT                          *
     ********************************************/
    if ($("#description").length) {
        CKEDITOR.replace("description");
    }

    $("#start_date, #end_date").datetimepicker({
        format: "d/m/Y",
        timepicker: false,
    });

    var timelineCounter_edit;
    var formDataEdit = {};
    var tourIdSendingImage;
    var originalImageStems = [];

    $(document).on("click", ".edit-tour", function (e) {
        e.preventDefault();
        console.log("edittour-click");

        var tourId = $(this).data("tourid");
        var urlEdit = $(this).data("urledit");
        tourIdSendingImage = tourId;

        init_SmartWizard_Edit_Tour();

        var csrfToken = $('meta[name="csrf-token"]').attr("content");

        $.ajax({
            type: "GET",
            url: urlEdit,
            data: {
                _token: csrfToken,
                tourId: tourId,
            },
            success: function (response) {
                if (response.success) {
                    console.log(response);

                    const tour = response.tour;
                    const images = response.images;
                    const timeline = response.timeline;

                    loadOldImages(images);

                    // Gán giá trị cho datetimepicker
                    const startDate = moment(
                        tour.startDate,
                        "YYYY-MM-DD",
                    ).format("DD/MM/YYYY");

                    const endDate = moment(tour.endDate, "YYYY-MM-DD").format(
                        "DD/MM/YYYY",
                    );

                    // Điền dữ liệu vào các field
                    $("input[name='name']").val(tour.title);
                    $("input[name='destination']").val(tour.destination);
                    $("select[name='domain']").val(tour.domain);
                    $("input[name='number']").val(tour.quantity);
                    $("input[name='price_adult']").val(tour.priceAdult);
                    $("input[name='price_child']").val(tour.priceChild);
                    $("#start_date").val(startDate);
                    $("#end_date").val(endDate);

                    // Đảm bảo CKEditor đã sẵn sàng
                    CKEDITOR.instances["description"].on(
                        "instanceReady",
                        function () {
                            CKEDITOR.instances["description"].setData(
                                tour.description,
                            );
                        },
                    );

                    timelineCounter_edit = 1;

                    // Xóa các timeline hiện tại trước khi load dữ liệu
                    $("#step-3").empty();

                    // Duyệt qua mảng timeline và thêm vào giao diện
                    timeline.forEach((item) => {
                        editTimelineEntry(item);
                    });
                } else {
                    toastr.error(response.message);

                    setTimeout(() => {
                        $("#edit-tour-modal").modal("hide");
                    }, 500);
                }
            },
            error: function (xhr, textStatus, errorThrown) {
                toastr.error("Có lỗi xảy ra. Vui lòng thử lại sau.");
            },
        });
    });

    function getFormDataImages() {
        var formDataImages = [];

        if (!dropzoneOldImages) {
            return formDataImages;
        }

        dropzoneOldImages.files.forEach(function (file) {
            if (file.status !== "accepted" && file.status !== "complete") {
                return;
            }

            if (file.xhr && file.xhr.responseText) {
                try {
                    var response = JSON.parse(file.xhr.responseText);

                    if (
                        response.success &&
                        response.data &&
                        response.data.filename
                    ) {
                        formDataImages.push(response.data.filename);
                        return;
                    }
                } catch (e) {
                    console.error("Không đọc được response upload ảnh:", e);
                }
            }

            if (file.name) {
                formDataImages.push(file.name);
            }
        });

        formDataImages = [...new Set(formDataImages)];

        return formDataImages;
    }

    function saveEditTour() {
        var finishStep1 = true;

        $("#form-step1 input, #form-step1 select, #form-step1 textarea").each(
            function () {
                if ($(this).prop("required") && $(this).val().trim() === "") {
                    finishStep1 = false;
                    $(this).addClass("is-invalid");
                } else {
                    $(this).removeClass("is-invalid");
                }
            },
        );

        var domain = $("#domain").val();

        if (!domain) {
            finishStep1 = false;
            $("#domain").addClass("is-invalid");
        } else {
            $("#domain").removeClass("is-invalid");
        }

        var description = "";

        if (CKEDITOR.instances["description"]) {
            description = CKEDITOR.instances["description"].getData();
        }

        if (!description) {
            finishStep1 = false;
            toastr.error("Vui lòng điền mô tả!");
        }

        if (!finishStep1) {
            toastr.error("Vui lòng kiểm tra lại thông tin Bước 1.");
            return;
        }

        var formDataImages = getFormDataImages();

        var originalImages = [...originalImageStems].sort();
        var currentImages = [...formDataImages].sort();

        var imagesChanged =
            JSON.stringify(originalImages) !== JSON.stringify(currentImages);

        if (imagesChanged && formDataImages.length < 5) {
            toastr.error("Tour phải có ít nhất 5 ảnh.");
            return;
        }

        var timelines = [];

        $(".timeline-entry").each(function () {
            const title = $(this).find('input[name^="day"]').val();

            const textarea = $(this).find("textarea");

            const editor = CKEDITOR.instances[textarea.attr("id")];

            timelines.push({
                title: title,
                itinerary: editor ? editor.getData() : textarea.val(),
            });
        });

        if (timelines.length === 0) {
            toastr.error("Tour phải có ít nhất một ngày trong lộ trình.");
            return;
        }

        formDataEdit = {
            tourId: tourIdSendingImage,
            name: $("input[name='name']").val(),
            destination: $("input[name='destination']").val(),
            domain: $("#domain").val(),
            number: $("input[name='number']").val(),
            price_adult: $("input[name='price_adult']").val(),
            price_child: $("input[name='price_child']").val(),
            start_date: $("#start_date").val(),
            end_date: $("#end_date").val(),
            description: description,
            images: imagesChanged ? formDataImages : [],
            timeline: timelines,
            _token: $('input[name="_token"]').val(),
        };

        console.log("Dữ liệu Edit Tour:", formDataEdit);

        var urlUpdate = $("#timeline-form").attr("action");

        $.ajax({
            url: urlUpdate,
            type: "POST",
            data: formDataEdit,

            success: function (response) {
                if (response.success) {
                    $("#tbody-listTours").html(response.data);

                    $("#edit-tour-modal").modal("hide");

                    toastr.success(response.message);
                } else {
                    toastr.error(response.message);
                }
            },

            error: function (xhr) {
                toastr.error(
                    xhr.responseJSON?.message ||
                        "Có lỗi xảy ra. Vui lòng thử lại sau.",
                );
            },
        });
    }

    function init_SmartWizard_Edit_Tour() {
        if (typeof $.fn.smartWizard === "undefined") {
            return;
        }

        console.log("init_SmartWizard_Edit_Tour");

        $("#edit-tour-modal #wizard").smartWizard({
            enableAllSteps: true,
            noForwardJumping: false,
            transitionEffect: "slide",

            onLeaveStep: function () {
                return true;
            },

            onFinish: function () {
                saveEditTour();
                return false;
            },
        });

        Dropzone.autoDiscover = false;

        dropzoneOldImages = new Dropzone("#myDropzone-listTour", {
            url: "/admin/add-temp-images",
            method: "post",
            paramName: "image",
            acceptedFiles: "image/*",
            addRemoveLinks: true,
            dictRemoveFile: "Xóa ảnh",
            autoProcessQueue: true,
            maxFiles: 12,
            parallelUploads: 5,

            headers: {
                "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
            },

            init: function () {
                this.on("sending", function (file, xhr, formData) {
                    formData.append("tourId", tourIdSendingImage);
                });

                this.on("error", function (file, response) {
                    var msg = "Không tải được ảnh.";

                    if (typeof response === "string") {
                        msg = response;
                    } else if (response && response.message) {
                        msg = response.message;
                    } else if (response && response.errors) {
                        msg = Object.values(response.errors)[0][0];
                    }

                    var el =
                        file.previewElement &&
                        file.previewElement.querySelector(
                            "[data-dz-errormessage]",
                        );

                    if (el) {
                        el.textContent = msg;
                    }
                });
            },
        });

        $("#wizard_verticle").smartWizard({
            transitionEffect: "slide",
        });

        $(".buttonNext").addClass("btn btn-success");

        $(".buttonPrevious").addClass("btn btn-primary");

        $(".buttonFinish").addClass("btn btn-default");
    }

    function loadOldImages(images) {
        originalImageStems = images.map(function (image) {
            return image.imageURL;
        });

        images.forEach(function (image) {
            // thumbUrl do server trả về (getTourEdit); dự phòng cho ảnh dạng cũ chưa được chuyển
            let imageUrl =
                image.thumbUrl ||
                `/admin/assets/images/gallery-tours/${image.imageURL}`;

            let mockFile = {
                name: image.imageURL,
                url: imageUrl,
                status: "accepted",
            };

            // Thêm file vào Dropzone
            dropzoneOldImages.emit("addedfile", mockFile);

            dropzoneOldImages.emit("thumbnail", mockFile, imageUrl);

            dropzoneOldImages.emit("complete", mockFile);

            dropzoneOldImages.files.push(mockFile);
        });
    }

    function editTimelineEntry(data = null) {
        const title = data ? data.title : `Ngày ${timelineCounter_edit}`;

        const description = data ? data.description : "";

        const timelineEntry = `
        <div class="timeline-entry" id="timeline-entry-${timelineCounter_edit}">
            <label for="day-${timelineCounter_edit}">Ngày ${timelineCounter_edit}</label>
            <input type="text" class="form-control" id="day-${timelineCounter_edit}" 
                   name="day-${timelineCounter_edit}" 
                   placeholder="Ngày thứ..." 
                   value="${title}" 
                   required>
            
            <label for="itinerary-${timelineCounter_edit}" style="margin-top: 10px; display: block;">Lộ trình:</label>
            <textarea id="itinerary-${timelineCounter_edit}" name="itinerary-${timelineCounter_edit}" required>${description}</textarea>
        </div>
    `;

        // Thêm vào div#step-3
        $("#step-3").append(timelineEntry);

        // Khởi tạo CKEditor cho textarea vừa thêm
        if ($(`#itinerary-${timelineCounter_edit}`).length) {
            CKEDITOR.replace(`itinerary-${timelineCounter_edit}`);
        }

        // formDataEdit.timeline.push(itineraryData);

        timelineCounter_edit++;
    }

    // Khi modal đóng
    $("#edit-tour-modal").on("hidden.bs.modal", function () {
        console.log("close modal");

        // Reset SmartWizard về bước đầu tiên
        $("#edit-tour-modal #wizard").smartWizard("goToStep", 1);

        // Kiểm tra nếu Dropzone đã được khởi tạo
        if (Dropzone.forElement("#myDropzone-listTour") !== undefined) {
            // Hủy bỏ Dropzone cũ
            Dropzone.forElement("#myDropzone-listTour").destroy();
        }

        formDataEdit = {};
        originalImageStems = [];
        timelineCounter_edit = 1;
    });

    $(document).on("click", ".delete-tour", function (e) {
        e.preventDefault();

        var tourId = $(this).data("tourid");
        var urlDelete = $(this).attr("href");

        var csrfToken = $('meta[name="csrf-token"]').attr("content");

        $.ajax({
            type: "POST",
            url: urlDelete,
            data: {
                _token: csrfToken,
                tourId: tourId,
            },

            success: function (response) {
                if (response.success) {
                    $("#tbody-listTours").html(response.data);

                    toastr.success(response.message);
                } else {
                    toastr.error(response.message);
                }
            },

            error: function (xhr, textStatus, errorThrown) {
                toastr.error("Có lỗi xảy ra. Vui lòng thử lại sau.");
            },
        });
    });

    /********************************************
     * ADD TOURS                              *
     ********************************************/

    let timelineCounter = 1;
    let maxTimelineDays;

    $(document).on("dataUpdated", function (event, daysDifference) {
        maxTimelineDays = daysDifference;
    });

    // Hàm thêm một timeline entry mới
    function addTimelineEntry() {
        // Kiểm tra nếu số lượng timeline entries đã đạt giới hạn
        console.log(maxTimelineDays);

        if (timelineCounter > maxTimelineDays) {
            toastr.error(`Không thể thêm quá ${maxTimelineDays} ngày.`);
            return;
        }

        const timelineEntry = `
                <div class="timeline-entry" id="timeline-entry-${timelineCounter}">
                    <label for="day-${timelineCounter}">Ngày ${timelineCounter}</label>
                    <input type="text" class="form-control" id="day-${timelineCounter}" name="day-${timelineCounter}" placeholder="Ngày thứ..." required>
                    
                    <label for="itinerary-${timelineCounter}" style="margin-top: 10px; display: block;">Lộ trình:</label>
                    <textarea id="itinerary-${timelineCounter}" name="itinerary-${timelineCounter}" required></textarea>
                    
                    <button type="button" class="btn btn-round btn-danger remove-btn" data-id="${timelineCounter}">Xóa Timeline này</button>
                </div>
            `;

        // Thêm vào div#step-3
        $("#step-3").append(timelineEntry);

        // Khởi tạo CKEditor cho textarea vừa thêm
        if ($(`#itinerary-${timelineCounter}`).length) {
            CKEDITOR.replace(`itinerary-${timelineCounter}`);
        }

        timelineCounter++;
    }

    // Xử lý khi nhấn nút thêm timeline
    $("#step-3").on("click", "#add-timeline", function () {
        addTimelineEntry();
    });

    // Xử lý khi nhấn nút xóa timeline
    $("#step-3").on("click", ".remove-btn", function () {
        const id = $(this).data("id");

        $(`#timeline-entry-${id}`).remove();
    });

    // Thêm nút thêm timeline vào div#step-3
    const addButton = `<button type="button" id="add-timeline" class="btn btn-round btn-info" style="margin-top: 20px;">Thêm Timeline</button>`;

    $("#step-3").append(addButton);

    // Thêm timeline đầu tiên khi trang tải xong
    addTimelineEntry();

    $(".add-tours #wizard .buttonFinish")
        .off("click")
        .on("click", function (e) {
            e.preventDefault();

            const form = $("#timeline-form")[0];

            if (!form.checkValidity()) {
                toastr.error("Vui lòng điền đầy đủ thông tin trong form!");

                form.reportValidity();

                return;
            }

            $("#timeline-form textarea").each(function () {
                const instance = CKEDITOR.instances[this.id];

                if (instance) {
                    instance.updateElement();
                }
            });

            $.ajax({
                url: $("#timeline-form").attr("action"),

                type: "POST",

                data: $("#timeline-form").serialize(),

                success: function (response) {
                    if (response.success) {
                        toastr.success(response.message);

                        setTimeout(function () {
                            window.location.href = response.redirect;
                        }, 1500);
                    } else {
                        toastr.error(
                            response.message ||
                                "Không thể hoàn thành thêm tour.",
                        );
                    }
                },

                error: function (xhr) {
                    toastr.error(
                        xhr.responseJSON?.message ||
                            "Không thể hoàn thành thêm tour. Vui lòng thử lại.",
                    );
                },
            });
        });

    /********************************************
     * BOOKING MANAGEMENT                          *
     ********************************************/
    $(document).on("click", ".confirm-booking", function (e) {
        e.preventDefault();

        const bookingId = $(this).data("bookingid");

        const urlConfirm = $(this).data("urlconfirm");

        console.log("Booking ID:", bookingId);

        console.log("urlConfirm:", urlConfirm);

        // Thực hiện các hành động khác, ví dụ gọi AJAX
        $.ajax({
            url: urlConfirm,
            method: "POST",
            data: {
                bookingId: bookingId,
                _token: $('meta[name="csrf-token"]').attr("content"),
            },

            success: function (response) {
                if (response.success) {
                    if ($.fn.DataTable.isDataTable("#datatable-booking")) {
                        $("#datatable-booking").DataTable().destroy();
                    }

                    $("#tbody-booking").html(response.data);

                    init_DataTables();

                    toastr.success(response.message);
                } else {
                    toastr.error(response.message);
                }
            },

            error: function (error) {
                toastr.error("Có lỗi xảy ra. Vui lòng thử lại sau.");
            },
        });
    });

    $(document).on("click", ".finish-booking", function (e) {
        e.preventDefault();

        const bookingId = $(this).data("bookingid");

        const urlFinish = $(this).data("urlfinish");

        console.log("Booking ID:", bookingId);

        console.log("urlFinish:", urlFinish);

        // Thực hiện các hành động khác, ví dụ gọi AJAX
        $.ajax({
            url: urlFinish,
            method: "POST",
            data: {
                bookingId: bookingId,
                _token: $('meta[name="csrf-token"]').attr("content"),
            },

            success: function (response) {
                if (response.success) {
                    if ($.fn.DataTable.isDataTable("#datatable-booking")) {
                        $("#datatable-booking").DataTable().destroy();
                    }

                    $("#tbody-booking").html(response.data);

                    init_DataTables();

                    toastr.success(response.message);
                } else {
                    toastr.error(response.message);
                }
            },

            error: function (error) {
                toastr.error("Có lỗi xảy ra. Vui lòng thử lại sau.");
            },
        });
    });

    $(document).on("click", ".confirm-payment", function (e) {
        e.preventDefault();

        const bookingId = $(this).data("bookingid");

        const urlPayment = $(this).data("urlpayment");

        $.ajax({
            url: urlPayment,
            method: "POST",
            data: {
                bookingId: bookingId,
                _token: $('meta[name="csrf-token"]').attr("content"),
            },

            success: function (response) {
                if (response.success) {
                    if ($.fn.DataTable.isDataTable("#datatable-booking")) {
                        $("#datatable-booking").DataTable().destroy();
                    }

                    $("#tbody-booking").html(response.data);

                    init_DataTables();

                    toastr.success(response.message);
                } else {
                    toastr.error(response.message);
                }
            },

            error: function () {
                toastr.error("Có lỗi xảy ra. Vui lòng thử lại sau.");
            },
        });
    });

    /********************************************
     * BOOKING INVOICE                          *
     ********************************************/
    $("#send-pdf-btn").click(function () {
        // Lấy bookingId và email từ button
        const bookingId = $(this).data("bookingid");

        const email = $(this).data("email");

        const urlSendPdf = $(this).data("urlsendmail");

        // Gửi AJAX request
        $.ajax({
            url: urlSendPdf,
            type: "POST",
            data: {
                bookingId: bookingId,
                email: email,
                _token: $('meta[name="csrf-token"]').attr("content"),
            },

            beforeSend: function () {
                toastr.warning("Đang gửi mail!!!");
            },

            success: function (response) {
                if (response.success) {
                    toastr.success(response.message);
                } else {
                    toastr.error(response.message);
                }
            },

            error: function (xhr, status, error) {
                toastr.error("Đã xảy ra lỗi khi gửi email. Vui lòng thử lại!");

                console.error(xhr.responseText);
            },
        });
    });

    $(document).on("click", "#received-money", function (e) {
        e.preventDefault();

        const bookingId = $(this).data("bookingid");

        const urlPaid = $(this).data("urlpaid");

        console.log("Booking ID:", bookingId);

        console.log("url:", urlPaid);

        // Thực hiện các hành động khác, ví dụ gọi AJAX
        $.ajax({
            url: urlPaid,
            method: "POST",
            data: {
                bookingId: bookingId,
                _token: $('meta[name="csrf-token"]').attr("content"),
            },

            success: function (response) {
                if (response.success) {
                    $("#received-money").remove();

                    toastr.success(response.message);
                } else {
                    toastr.error(response.message);
                }
            },

            error: function (error) {
                toastr.error("Có lỗi xảy ra. Vui lòng thử lại sau.");
            },
        });
    });

    /********************************************
     * CONTACT MANAGEMENT                       *
     ********************************************/
    $(".contact-item").click(function (e) {
        e.preventDefault();

        $(".mail_view").show();

        // Lấy dữ liệu từ các thuộc tính data-*
        var fullName = $(this).data("name");
        var email = $(this).data("email");
        var message = $(this).data("message");
        var contactId = $(this).data("contactid");

        $(".mail_view .inbox-body .sender-info strong").text(fullName);

        $(".mail_view .inbox-body .sender-info span").text("(" + email + ")");

        $(".mail_view .view-mail p").text(message);

        // Thêm thuộc tính data-email vào button
        $(".send-reply-contact").attr("data-email", email);

        $(".send-reply-contact").attr("data-contactid", contactId);
    });

    if ($("#editor-contact").length) {
        CKEDITOR.replace("editor-contact");
    }

    $(document).on("click", ".send-reply-contact", function (e) {
        e.preventDefault();

        // Lấy thông tin từ nút gửi
        var email = $(this).attr("data-email");

        var contactId = $(this).attr("data-contactid");

        var editorContent = CKEDITOR.instances["editor-contact"].getData();

        var urlReply = $(this).data("url");

        if (!email) {
            toastr.error("Không có địa chỉ email để gửi.");

            return;
        }

        // Gửi AJAX request
        $.ajax({
            url: urlReply,
            type: "POST",
            dataType: "json",

            headers: {
                "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
            },

            data: {
                contactId: contactId,
                email: email,
                message: editorContent,
            },

            success: function (response) {
                if (response.success) {
                    toastr.success(response.message);

                    // Xóa element contact-item sau khi phản hồi thành công
                    $(
                        ".contact-item[data-contactid='" + contactId + "']",
                    ).remove();

                    $(".mail_view").hide();

                    CKEDITOR.instances["editor-contact"].setData("");

                    $("#editor-contact").empty();

                    $(".compose").slideToggle();

                    $(this)
                        .removeAttr("data-email")
                        .removeAttr("data-contactid");
                }
            },

            error: function (xhr) {
                alert("Đã xảy ra lỗi khi gửi email. Vui lòng thử lại.");
            },
        });
    });

    /********************************************
     * LOGIN ADMIN                             *
     ********************************************/
    $("#formLoginAdmin").on("submit", function (e) {
        const username = $("#username").val();
        const password = $("#password").val();

        // Biểu thức chính quy an toàn
        const sqlInjectionPattern = /['";=\\-]/;

        // Validate username
        if (sqlInjectionPattern.test(username)) {
            toastr.error("Tên tài khoản chứa ký tự không hợp lệ!");

            e.preventDefault();

            return false;
        }

        // Validate password
        if (sqlInjectionPattern.test(password)) {
            toastr.error("Mật khẩu chứa ký tự không hợp lệ!");

            e.preventDefault();

            return false;
        }

        // Đảm bảo mật khẩu có ít nhất 6 ký tự
        if (password.length < 6) {
            toastr.error("Mật khẩu phải có ít nhất 6 ký tự!");

            e.preventDefault();

            return false;
        }
    });

    /********************************************
     * ADMIN MANAGEMENT                        *
     ********************************************/

    $("#formProfileAdmin").on("submit", function (e) {
        e.preventDefault();

        var form = $(this);

        var name = form.find('[name="fullName"]').val().trim();

        var password = form.find('[name="password"]').val().trim();

        var email = form.find('[name="email"]').val().trim();

        var address = form.find('[name="address"]').val().trim();

        var isValid = true;

        // Mật khẩu để trống = không đổi; nếu nhập thì phải đủ 6 ký tự
        if (password !== "" && password.length < 6) {
            isValid = false;

            toastr.error("Mật khẩu mới phải có ít nhất 6 ký tự.");
        }

        var emailPattern = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;

        if (!emailPattern.test(email)) {
            isValid = false;

            toastr.error("Email không hợp lệ.");
        }

        if (address === "") {
            isValid = false;

            toastr.error("Vui lòng nhập địa chỉ.");
        }

        if (isValid) {
            $.ajax({
                url: form.attr("action"),
                method: "POST",

                data: {
                    fullName: name,
                    password: password,
                    email: email,
                    address: address,
                    _token: $('meta[name="csrf-token"]').attr("content"),
                },

                success: function (response) {
                    if (response.success) {
                        toastr.success("Cập nhật thành công!");

                        $("#nameAdmin").text(response.data.fullName);

                        $("#emailAdmin").text(response.data.email);

                        $("#addressAdmin").text(response.data.address);

                        form.find('[name="password"]').val("");
                    } else {
                        toastr.error(response.message);
                    }
                },

                error: function (xhr) {
                    // 422: lỗi kiểm tra dữ liệu của Laravel, hiện thông báo đầu tiên
                    if (
                        xhr.status === 422 &&
                        xhr.responseJSON &&
                        xhr.responseJSON.errors
                    ) {
                        var first = Object.values(
                            xhr.responseJSON.errors,
                        )[0][0];

                        toastr.error(first);
                    } else {
                        toastr.error("Đã có lỗi xảy ra. Vui lòng thử lại!");
                    }
                },
            });
        }
    });

    //Update avatar
    $("#avatarAdmin").on("change", function () {
        const file = event.target.files[0];

        if (file) {
            // Hiển thị ảnh vừa chọn trước khi gửi lên server
            const reader = new FileReader();

            reader.onload = function (e) {
                $("#avatarAdminPreview").attr("src", e.target.result);

                $("#navbarDropdown img").attr("src", e.target.result);

                $(".profile_img").attr("src", e.target.result);
            };

            reader.readAsDataURL(file);

            var url = $("#btn_avatar").attr("action");

            // Tạo FormData để gửi file qua AJAX
            const formData = new FormData();

            formData.append("avatarAdmin", file);

            console.log(formData);

            // Gửi AJAX đến server
            $.ajax({
                url: url,
                type: "POST",

                headers: {
                    "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr(
                        "content",
                    ),
                },

                data: formData,
                contentType: false,
                processData: false,

                success: function (response) {
                    if (response.success) {
                        toastr.success(response.message);
                    } else {
                        toastr.error(response.message);
                    }
                },

                error: function (xhr, status, error) {
                    toastr.error("Có lỗi xảy ra. Vui lòng thử lại sau.");
                },
            });
        }
    });

    /********************************************
     * DASHBOARD                                  *
     ********************************************/
});
