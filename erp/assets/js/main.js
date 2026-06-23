$(function () {
  $('.navbar-nav .nav-link, .quote-btn').on('click', function () {
    if ($(window).width() < 992) {
      $('.navbar-collapse').collapse('hide');
    }
  });

  $(window).on('scroll', function () {
    if ($(this).scrollTop() > 300) {
      $('#backToTop').fadeIn(150);
    } else {
      $('#backToTop').fadeOut(150);
    }
  });

  $('#backToTop').on('click', function () {
    $('html, body').animate({ scrollTop: 0 }, 450);
  });
});
