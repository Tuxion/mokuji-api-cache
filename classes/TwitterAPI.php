<?php namespace components\api_cache\classes; if(!defined('TX')) die('No direct access.');

load_plugin('codebird');

/**
 * Wrapper for querying the Twitter API (version 1.1)
 */
class TwitterAPI
{
  
  /**
   * Codebird instance.
   * @var \Codebird\Codebird
   */
  protected $codebird;
  
  /**
   * Service model we're using.
   * @var \components\api_cache\models\Services
   */
  protected $service;
  
  public function __construct($service)
  {
    
    //Get the service info.
    $this->service = $service;
    
    //Get the credentials set.
    $this->_reset_credentials();
    
    //Get the bird.
    $this->codebird = new \Codebird\Codebird();
    
  }
  
  public function search($parameters)
  {
    
    raw($parameters);
    
    $query = 'search/tweets?'.http_build_query($parameters);
    $service = $this->service;
    
    $queryModel = $this->_get_query_model($service, $query);

    if($queryModel->is_valid_cache->get('boolean'))
      return $queryModel->save()->response;
    
    mk('Logging')->log('API Cache', 'Performing request', $query);
    
    $this->codebird->setReturnFormat(CODEBIRD_RETURNFORMAT_ARRAY);
    $reply = $this->codebird->search_tweets($parameters, true);
    
    $status = $reply['httpstatus'];
    unset($reply['httpstatus']);
    
    //Check if we need to get a new bearer token.
    if($this->_check_bearer_token($status, $reply)){
      $service
        ->oauth2_credentials
        ->merge(array(
          'bearer_token'=>'NULL'
        ))
        ->save();
      $this->_reset_credentials();
      return $this->search($parameters);
    }
    
    return $queryModel
      ->bump_executes()
      ->merge(array(
        'dt_executed' => date('Y-m-d H:i:s'),
        'response' => json_encode($reply)
      ))
      
      //Don't store errors.
      ->is($status === 200, function($queryModel){
        $queryModel->save();
      })
      
      ->response;

  }

  public function user_timeline($parameters)
  {
    
    raw($parameters);
    
    $query = 'statuses/user_timeline?'.http_build_query($parameters);
    $service = $this->service;
    
    $queryModel = $this->_get_query_model($service, $query);

    if($queryModel->is_valid_cache->get('boolean'))
      return $queryModel->save()->response;
    
    mk('Logging')->log('API Cache', 'Performing request', $query);
    
    $this->codebird->setReturnFormat(CODEBIRD_RETURNFORMAT_ARRAY);
    $reply = $this->codebird->statuses_userTimeline($parameters, true);
    
    $status = $reply['httpstatus'];
    unset($reply['httpstatus']);
    
    //Check if we need to get a new bearer token.
    if($this->_check_bearer_token($status, $reply)){
      $service
        ->oauth2_credentials
        ->merge(array(
          'bearer_token'=>'NULL'
        ))
        ->save();
      $this->_reset_credentials();
      return $this->user_timeline($parameters);
    }
    
    return $queryModel
      ->bump_executes()
      ->merge(array(
        'dt_executed' => date('Y-m-d H:i:s'),
        'response' => json_encode($reply)
      ))
      
      //Don't store errors.
      ->is($status === 200, function($queryModel){
        $queryModel->save();
      })
      
      ->response;
    
  }
  
  protected function _get_query_model($service, $query)
  {
    
    $queryModel = mk('Sql')
      ->table('api_cache', 'ServiceQueries')
      ->where('service_id', $service->id)
      ->where('query_hash', "'".sha1($query)."'")
      ->execute_single()
      ->is('empty', function()use($service, $query){
        
        return mk('Sql')
          ->model('api_cache', 'ServiceQueries')
          ->set(array(
            'service_id' => $service->id,
            'query' => $query,
            'query_hash' => sha1($query)
          ));
        
      });
    
    $queryModel->bump_requests();

    return $queryModel;

  }

  protected function _check_bearer_token($status, $reply)
  {
    return $status === 401 && isset($reply['errors']) && isset($reply['errors'][0]['code']) && $reply['errors'][0]['code'] === 89;
  }

  protected function _reset_credentials()
  {
    
    //We need this always.
    \Codebird\Codebird::setConsumerKey(
      $this->service->oauth2_credentials->api_key->get(),
      $this->service->oauth2_credentials->api_secret->get()
    );
    
    //Check if we have a bearer token.
    if($this->service->oauth2_credentials->bearer_token->is_set()){
      \Codebird\Codebird::setBearerToken($this->service->oauth2_credentials->bearer_token->get());
    }
    
    //Set the oauth keys to get a bearer token anyway.
    else {
      
      $this->service->oauth2_credentials->merge(array(
        'bearer_token' => \Codebird\Codebird::getInstance()
          ->oauth2_token()
          ->access_token
      ))->save();
      
      \Codebird\Codebird::setBearerToken($this->service->oauth2_credentials->bearer_token->get());
      
    }
    
  }
  
}