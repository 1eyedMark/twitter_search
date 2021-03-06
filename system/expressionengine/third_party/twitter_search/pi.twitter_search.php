<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/*
 * Twitter Search 2 module by Exp:resso (support@exp-resso.com)
 * Concept based on Twitter Search for EE 1.6 by David Rencher (http://www.lumis.com/)
 *
 * Copyright (c) 2010-2012 Crescendo Multimedia Ltd
 * All rights reserved.
 *
 * This software is licensed under a Creative Commons Attribution-ShareAlike 3.0 License.
 * http://creativecommons.org/licenses/by-sa/3.0/
 *
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR "AS IS" AND ANY EXPRESS OR
 * IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES
 * OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT
 * NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF
 * THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

$plugin_info = array(
	'pi_name'			=> 'Twitter Search 2',
	'pi_version'		=> '2.0.8',
	'pi_author'			=> 'Exp:resso',
	'pi_author_url'		=> 'http://exp-resso.com/',
	'pi_description'	=> 'Find tweets based on search text or location',
	'pi_usage'			=> Twitter_search::usage()
);

class Twitter_search
{
	const TWITTER_URL = 'http://search.twitter.com/search.json?callback=';
	public $return_data = '';

	public function Twitter_search()
	{
		$this->EE =& get_instance();

		// detect cURL library
		if ( ! function_exists('curl_init'))
		{
			$this->return_data = 'The cURL library must be installed and enabled to use Twitter Search!';
			return;
		}

		if (trim($this->EE->TMPL->tagdata) == '') { return; }

		$json_data = $this->query_twitter($this->EE->TMPL->tagparams);
		if (empty($json_data))
		{
			// no twitter results
			$this->return_data = $this->no_tweets($this->EE->TMPL->tagdata);
			return;
		}
		elseif (is_string($json_data))
		{
			// probably a cURL error
			$this->return_data = $json_data;
			return;
		}
		elseif ( ! empty($json_data->error))
		{
			// Twitter API returned error
			$this->return_data = $json_data->error;
			return;
		}

		// check we found some tweets
		$total_results = count($json_data->results);
		if ($total_results == 0)
		{
			$this->return_data = $this->no_tweets($this->EE->TMPL->tagdata);
			return;
		}

		// configure typography library
		$this->EE->load->library('typography');
		$this->EE->typography->initialize();

		$this->EE->typography->allow_img_url = 'n';
		$this->EE->typography->auto_links = $this->EE->TMPL->fetch_param('auto_links') != "no";
		$this->EE->typography->convert_curly = FALSE;
		$this->EE->typography->parse_smileys = FALSE;
		$this->EE->typography->text_format = "none";
		$this->EE->typography->word_censor = $this->EE->TMPL->fetch_param('word_censor') != "no";

		$prefix = $this->EE->TMPL->fetch_param('var_prefix');
		$prefix = $prefix ? $prefix.':' : '';

		// loop over tweets and build array for template
		$tweets = array();
		$count = 0;
		foreach($json_data->results as $result)
		{
			$count++;

			$tweet = array();
			$tweet[$prefix.'count'] = $count;
			$tweet[$prefix.'total_results'] = $total_results;

			foreach (array('id', 'from_user', 'from_user_id', 'to_user', 'to_user_id',
				'profile_image_url', 'iso_language_code') as $key)
			{
				$tweet[$prefix.$key] = isset($result->$key) ? (string) $result->$key : '';
			}

			$tweet[$prefix.'tweet_url'] = 'http://twitter.com/'.$tweet[$prefix.'from_user'].'/status/'.$tweet[$prefix.'id'];

			// run tweet text through typography class
			$tweet[$prefix.'text'] = $this->EE->typography->parse_type($result->text);

			// parse @usernames and #hashtags
			if ($this->EE->TMPL->fetch_param('auto_links') != "no")
			{
				if (strpos($tweet[$prefix.'text'], '@') !== FALSE)
				{
					$tweet[$prefix.'text'] = preg_replace('/(^|[^\w])@(\w+)/', '$1@<a href="http://twitter.com/$2">$2</a>', $tweet[$prefix.'text']);
				}
				if (strpos($tweet[$prefix.'text'], '#') !== FALSE)
				{
					$tweet[$prefix.'text'] = preg_replace('/(^|[^\w])#(\w+)/', '$1<a href="http://twitter.com/search/%23$2">#$2</a>', $tweet[$prefix.'text']);
				}
			}

			// do we need to nofollow links?
			if ($this->EE->typography->auto_links AND $this->EE->TMPL->fetch_param('nofollow') != "no")
			{
				$tweet[$prefix.'text'] = str_ireplace('<a href="', '<a rel="nofollow" href="', $tweet[$prefix.'text']);
			}

			// source is no longer being returned by the Twitter API, may still exist in templates..
			$tweet[$prefix.'source'] = '';

			// php datestamps
			$tweet[$prefix.'tweet_date'] = strtotime($result->created_at);
			$tweet[$prefix.'relative_tweet_date'] = $this->EE->localize->format_timespan(time() - $tweet[$prefix.'tweet_date']);

			// legacy date vars
			$tweet[$prefix.'created_at'] = $tweet[$prefix.'tweet_date'];
			$tweet[$prefix.'relative_date'] = $tweet[$prefix.'relative_tweet_date'];

			$tweet['no_tweets'] = FALSE;

			$tweets[] = $tweet;
		}

		// output template content
		$this->return_data = $this->EE->TMPL->parse_variables($this->EE->TMPL->tagdata, $tweets);
	}

	private function query_twitter($tagparams)
	{
		$query_url = self::TWITTER_URL;
		$skip_vars = array('cache', 'refresh', 'word_censor', 'auto_links', 'var_prefix');

		// generate query string
		foreach ($tagparams as $key => $value)
		{
			if ( ! in_array($key, $skip_vars))
			{
				$query_url .= "&".$key."=".urlencode($value);
			}
		}

		// request data from twitter
		if ( ! extension_loaded('curl') OR ! function_exists('curl_init'))
		{
			return "cURL library not found!";
		}

		$ch = curl_init();
		curl_setopt ($ch, CURLOPT_URL, $query_url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);

		$return_data = curl_exec($ch);
		$curl_error = curl_error($ch);

		// check for curl errors
		if (empty($curl_error))
		{
			// decode JSON data
			return json_decode($return_data);
		}
		else
		{
			return $curl_error;
		}
	}

	private function no_tweets($tagdata)
	{
		// based on no_results code in ./system/expressionengine/libraries/Template.php
		if (strpos($tagdata, 'if no_tweets') !== FALSE && preg_match("/".LD."if no_tweets".RD."(.*?)".LD.'\/'."if".RD."/s", $tagdata, $match))
		{
			if (stristr($match[1], LD.'if'))
			{
				$match[0] = $this->EE->functions->full_tag($match[0], $tagdata, LD.'if', LD.'\/'."if".RD);
			}

			// return the no_tweets template
			return substr($match[0], strlen(LD."if no_tweets".RD), -strlen(LD.'/'."if".RD));
		}
		else
		{
			return '';
		}
	}

	public static function usage()
	{
		// for performance only load README if inside control panel
		$EE =& get_instance();
		return isset($EE->cp) ? file_get_contents(PATH_THIRD.'twitter_search/README.md') : '';
	}
}

/* End of file pi.twitter_search.php */