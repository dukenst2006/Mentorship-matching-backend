window.MentorsListController = function () {
};

window.MentorsListController.prototype = function () {

    var mentorsAndMenteesListsCssCorrector,
        mentorsCriteria = {},
        deleteMentorBtnHandler = function () {
            $("body").on("click", ".deleteMentorBtn", function (e) {
                e.stopPropagation();
                var mentorId = $(this).attr("data-mentorId");
                $('#deleteMentorModal').modal('toggle');
                $('#deleteMentorModal').find('input[name="mentor_id"]').val(mentorId);
            });
        },
        editMentorStatusBtnHandler = function () {
            $("body").on("click", ".editMentorStatusBtn", function (e) {
                e.stopPropagation();
                var mentorId = $(this).attr("data-mentorId");
                var originalStatusId = $(this).attr("data-original-status");
                var $editMentorStatusModal = $('#editMentorStatusModal');
                $editMentorStatusModal.modal('toggle');
                $editMentorStatusModal.find('input[name="mentor_id"]').val(mentorId);
                $editMentorStatusModal.find('select[name="status_id"]').attr("data-original-value", originalStatusId);
                $editMentorStatusModal.find('select[name="status_id"]').val(originalStatusId).trigger("chosen:updated");
            });
        },
        initializeHandlers = function() {
            deleteMentorBtnHandler();
            editMentorStatusBtnHandler();
            cardTabClickHandler();
            searchBtnHandler();
            clearSearchBtnHandler();
        },
        searchBtnHandler = function () {
            $("#searchBtn").on("click", function () {
                mentorsCriteria.mentorName = $('input[name=mentorName]').val();
                mentorsCriteria.ageRange = $('input[name=age]').val();
                mentorsCriteria.specialtyId = $('select[name=specialty]').val();
                mentorsCriteria.companyId = $('select[name=company]').val();
                mentorsCriteria.availabilityId = $('select[name=availability]').val();
                mentorsCriteria.residenceId = $('select[name=residence]').val();
                mentorsCriteria.completedSessionsCount = $('select[name=completedSessionsCount]').val();
                mentorsCriteria.displayOnlyExternallySubscribed = $('input[name=only_externally_subscribed]').parent().hasClass("checked");
                mentorsCriteria.displayOnlyAvailableWithCancelledSessions = $('input[name=available_with_cancelled_session]').parent().hasClass("checked");
                getMentorsByFilter.call(this);
            });
        },
        clearSearchBtnHandler = function () {
            $("#clearSearchBtn").on("click", function () {
                $('input[name=mentorName]').val("");
                $('#age').data("ionRangeSlider").reset();
                $('select[name=specialty]').val(0).trigger("chosen:updated");
                $('select[name=company]').val(0).trigger("chosen:updated");
                $('select[name=availability]').val(0).trigger("chosen:updated");
                $('select[name=residence]').val(0).trigger("chosen:updated");
                $('select[name=completedSessionsCount]').val(0).trigger("chosen:updated");
                $('input[name=only_externally_subscribed]').iCheck('uncheck');
                $('input[name=available_with_cancelled_session]').iCheck('uncheck');
                // clear mentorsCriteria object from all of its properties
                for(var prop in mentorsCriteria) {
                    if(mentorsCriteria.hasOwnProperty(prop)) {
                        delete mentorsCriteria[prop];
                    }
                }
                getMentorsByFilter.call(this);
            });
        },
        getMentorsByFilter = function () {
            // button pressed that triggered this function
            var self = this;
            $.ajax({
                method: "GET",
                url: $(".filtersContainer").data("url"),
                cache: false,
                data: mentorsCriteria,
                beforeSend: function () {
                    $(self).parents('.panel-body').first().append('<div class="refresh-container"><div class="loading-bar indeterminate"></div></div>');
                },
                success: function (response) {
                    $('.refresh-container').fadeOut(500, function() {
                        $('.refresh-container').remove();
                    });
                    parseSuccessData(response);
                    mentorsAndMenteesListsCssCorrector.setCorrectCssClasses("#mentorsList");
                },
                error: function (xhr, status, errorThrown) {
                    $('.refresh-container').fadeOut(500, function() {
                        $('.refresh-container').remove();
                    });
                    console.log(xhr.responseText);
                    $("#errorMsg").removeClass('hidden');
                    //The message added to Response object in Controller can be retrieved as following.
                    $("#errorMsg").html(errorThrown);
                }
            });
        },
        parseSuccessData = function(response) {
            var responseObj = JSON.parse(response);
            //if operation was unsuccessful
            if (responseObj.status == 2) {
                $(".loader").addClass('hidden');
                $("#errorMsg").removeClass('hidden');
                $("#errorMsg").html(responseObj.data);
                $("#mentorsList").html("");
            } else {
                $("#mentorsList").html("");
                $("#errorMsg").addClass('hidden');
                $(".loader").addClass('hidden');
                $("#mentorsList").html(responseObj.data);
                Pleasure.listenClickableCards();
            }
        },
        initSelectInputs = function() {
            $(".chosen-select").chosen({
                width: '100%'
            });
        },
        initAgeRangeSlider = function() {
            $('#age').ionRangeSlider({
                min: 18,
                max: 75,
                from: 18,
                to: 75,
                type: 'double',
                step: 1,
                postfix: ' years old',
                grid: true
            });
        },
        cardTabClickHandler = function() {
            $('a[data-toggle="tab"]').click(function (e) {
                e.preventDefault();
                var objectId = $(".card_" + $(this).attr('data-id'));
                objectId.find('.tab-pane.active').removeClass('active');
                objectId.find('.tab-content.active').removeClass('active');
                objectId.find('div[id="' + $(this).attr('data-href') + '"]').addClass('active');
            });
        },
        init = function () {
            mentorsAndMenteesListsCssCorrector = new window.MentorsAndMenteesListsCssCorrector();
            mentorsAndMenteesListsCssCorrector.setCorrectCssClasses("#mentorsList");
            initializeHandlers();
            initSelectInputs();
            initAgeRangeSlider();
        };
    return {
        init: init
    }
}();
