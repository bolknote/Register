/**
 * Autosearch library
 *
 * @copyright 2011-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */


(function ()
{
	var xhr = false;

	if (window.XMLHttpRequest)
		xhr = new XMLHttpRequest();
	else if (window.ActiveXObject)
	{
		try
		{
			xhr = new ActiveXObject("Msxml2.XMLHTTP");
		}
		catch(e)
		{
			try
			{
				xhr = new ActiveXObject("Microsoft.XMLHTTP");
			}
			catch(e){ }
		}
	}

	var last_search, search_url = '', eCurItem = null;

	function doSearch (str)
	{
		xhr.open('GET', search_url + encodeURIComponent(str), true);
		xhr.onreadystatechange = function ()
		{
			if (xhr.readyState == 4 && xhr.status == 200)
				displayResults(xhr.responseText);
		};
		xhr.send(null);
		last_search = str;
	}

	function keyDown (e)
	{
		var iKey;
		if (window.event)
			iKey = window.event.keyCode;
		else if (e.keyCode)
			iKey = e.keyCode;
		else if (e.which)
			iKey = e.which;

		var stop_event = false;

		if (iKey == 13 && eCurItem)
		{
			var new_url = eCurItem.href;
			setTimeout(function ()
			{
				location.href = new_url;
			}, 0);
			SInp.form.action = '';
			hideResults();
			stop_event = true;
		}
		if (iKey == 27 && !STips.hidden)
		{
			var old_value = SInp.value;
			hideResults();
			setTimeout(function ()
			{
				SInp.value = old_value;
				SInp.focus();
			}, 0);
			stop_event = true;
		}
		if (iKey == 38 || iKey == 40)
		{
			if (!eCurItem)
			{
				var aeItems = STips.getElementsByTagName('A');
				if (aeItems.length)
				{
					eCurItem = aeItems[iKey == 38 ? aeItems.length - 1 : 0];
					eCurItem.className = 'current';
					if (STips.scrollTop > -20 + eCurItem.offsetTop)
						STips.scrollTop = -20 + eCurItem.offsetTop;
					if (STips.scrollTop < 20 + eCurItem.offsetTop + eCurItem.offsetHeight - STips.offsetHeight)
						STips.scrollTop = 20 + eCurItem.offsetTop + eCurItem.offsetHeight - STips.offsetHeight;
				}
			}
			else
			{
				var eItem = eCurItem;
				eCurItem.className = '';
				steps:
				{
					while (iKey == 38 ? eItem.previousSibling : eItem.nextSibling)
					{
						eItem = iKey == 38 ? eItem.previousSibling : eItem.nextSibling;
						if (eItem.nodeName == 'A')
						{
							eCurItem = eItem;
							eCurItem.className = 'current';
							if (STips.scrollTop > -20 + eCurItem.offsetTop)
								STips.scrollTop = -20 + eCurItem.offsetTop;
							else if (STips.scrollTop < 20 + eCurItem.offsetTop + eCurItem.offsetHeight - STips.offsetHeight)
								STips.scrollTop = 20 + eCurItem.offsetTop + eCurItem.offsetHeight - STips.offsetHeight;
							break steps;
						}
					}
					eCurItem =  null;
				}
			}
			stop_event = true;
		}

 		if (stop_event)
		{
 			if (window.event)
			{
				window.event.cancelBubble = true;
				window.event.returnValue = false;
			}
			try
			{
				e.stopPropagation();
				e.preventDefault();
			}
			catch (error) {}
 			return false;
		}
	}

	var STips;

	function displayResults (sHTML)
	{
		if (!sHTML)
		{
			hideResults();
			return;
		}

		STips.innerHTML = sHTML;
		STips.hidden = false;

		STips.scrollTop = 0;
	}

	function hideResults ()
	{
		if (STips)
			STips.hidden = true;
		eCurItem = null;
	}

	function hide ()
	{
		blur_timer = setTimeout(function ()
		{
			last_search = '';
			hideResults();
		}, 20);
	}

	var SInp, search_timer, blur_timer;

	function init ()
	{
		// We have nothing to do without Ajax support
		if (!xhr)
			return;

		SInp = document.getElementById('s2_search_input');
		if (!SInp)
			SInp = document.getElementById('s2_search_input_ext');
		if (!SInp)
			return;
		search_url = SInp.getAttribute('data-s2-search-url') || '';
		if (!search_url)
			return;

		// Search field events
		SInp.onkeydown = keyDown;
		SInp.onkeyup = function (e)
		{
			var new_search = SInp.value.replace(/^\s\s*/, '').replace(/\s\s*$/, '');
			if (last_search != new_search)
			{
				clearTimeout(search_timer);
				if (new_search.length >= 1)
					search_timer = setTimeout(function () { doSearch(new_search); }, 250);
				else
				{
					last_search = '';
					hideResults();
				}
			}
		};
		SInp.onclick = function (e)
		{
			clearTimeout(blur_timer);
		};
		SInp.form.onsubmit = function (e)
		{
			if (eCurItem)
			{
				// IE <= 7 fixes
				var new_url = eCurItem.href;
				location.href = new_url;
				hideResults();
				return false;
			}
			return !!SInp.form.action;
		};
		SInp.setAttribute('autocomplete', 'off');

		// Autosearch results div
		STips = document.createElement('div');
		STips.hidden = true;
		STips.id = 's2_search_tip';
		(SInp.closest('.s2-search-autocomplete') || SInp.form || document.body).appendChild(STips);

		if (typeof(document.addEventListener) == 'undefined')
			document.attachEvent('onclick', hide);
		else
			document.addEventListener('click', hide, true);
	}

	if (window.attachEvent)
		window.attachEvent('onload', init);
	else if (window.addEventListener)
		window.addEventListener('load', init, false);

})();
