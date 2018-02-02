document.addEventListener("DOMContentLoaded", function(event) {
    if (typeof cnswp !== 'undefined'){
        var skips = ['hero','button-group', 'pagination'];
        for (var i = 0; i < skips.length; i++){
            var x = document.getElementsByClassName(skips[i]);
            for (var j = 0; j < x.length;  j++) {
                x[j].style.display = "none";
            }
        }
    }

});

//Window reload timeout
var $timeout;

function MM_jumpMenu(targ,selObj,restore){ //v3.0
    eval(targ+".location='"+selObj.options[selObj.selectedIndex].value+"'");
    if (restore) selObj.selectedIndex=0;
}

function setCookie(key, value) {
    var expires = new Date();
    expires.setTime(expires.getTime() + (1 * 24 * 60 * 60 * 1000));
    document.cookie = key + '=' + value + ';expires=' + expires.toUTCString();
}

(function($){

    function updateCnsLimitedCookie(){
        var $vals = "";
        $(".cnslimited-checkbox").each(function(){
            if ($(this).is(":checked")) {
                $vals += " " + $(this).attr("value");
            }
        });
        setCookie("cnswp-selected", $vals);
    }

    $(document).ready(function(){

        $(".cnslimited-checkbox").click(function(){
            clearTimeout($timeout);
            updateCnsLimitedCookie();
            $timeout = setTimeout(function(){
                window.location.reload(true);
            }, 1500);         
        });

//        $("#training-type-menu li").mouseover(function(){
//            var boxen = $(this).find(".cnslimited-checkbox");
//            $("#help-text").html($(boxen[0]).attr("title"));  
//        });
//        $("#training-type-menu li").mouseout(function(){
//            $("#help-text").html("&nbsp;");  
//        });

    });
})(jQuery);
