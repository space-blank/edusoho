<?php
namespace Topxia\WebBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Topxia\Common\ArrayToolkit;
use Topxia\Common\StringToolkit;
use Topxia\Component\Payment\Payment;
use Topxia\WebBundle\Util\AvatarAlert;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\SecurityContext;

class CourseOrderController extends OrderController
{
    public $courseId = 0;

    public function buyAction(Request $request, $id)
    {
        $course = $this->getCourseService()->getCourse($id);

        $user = $this->getCurrentUser();

        if (!$user->isLogin()) {
            throw $this->createAccessDeniedException();
        }

        $remainingStudentNum = $this->getRemainStudentNum($course);

        $previewAs = $request->query->get('previewAs');

        $payTypeChoices = $request->query->get('payTypeChoices');
        
        $member = $user ? $this->getCourseService()->getCourseMember($course['id'], $user['id']) : null;
        $member = $this->previewAsMember($previewAs, $member, $course);

        $courseSetting = $this->getSettingService()->get('course', array());

        $userInfo = $this->getUserService()->getUserProfile($user['id']);
        $userInfo['approvalStatus'] = $user['approvalStatus'];
        
        $code = 'ChargeCoin';
        $ChargeCoin = $this->getAppService()->findInstallApp($code);

        $account=$this->getCashService()->getAccountByUserId($user['id'],true);
        
        if(empty($account)){
            $this->getCashService()->createAccount($user['id']);
        }

        if(isset($account['cash']))
        $account['cash']=intval($account['cash']);
    
        $amount=$this->getOrderService()->analysisAmount(array('userId'=>$user->id,'status'=>'paid'));
        $amount+=$this->getCashOrdersService()->analysisAmount(array('userId'=>$user->id,'status'=>'paid'));
        
        $course = $this->getCourseService()->getCourse($id);
       
        $userFields=$this->getUserFieldService()->getAllFieldsOrderBySeqAndEnabled();

        for($i=0;$i<count($userFields);$i++){
           if(strstr($userFields[$i]['fieldName'], "textField")) $userFields[$i]['type']="text";
           if(strstr($userFields[$i]['fieldName'], "varcharField")) $userFields[$i]['type']="varchar";
           if(strstr($userFields[$i]['fieldName'], "intField")) $userFields[$i]['type']="int";
           if(strstr($userFields[$i]['fieldName'], "floatField")) $userFields[$i]['type']="float";
           if(strstr($userFields[$i]['fieldName'], "dateField")) $userFields[$i]['type']="date";
        }

        if ($remainingStudentNum == 0 && $course['type'] == 'live') {
            return $this->render('TopxiaWebBundle:CourseOrder:remainless-modal.html.twig', array(
                'course' => $course
            ));
        }

        $oldOrders = $this->getOrderService()->searchOrders(array(
                'targetType' => 'course',
                'targetId' => $course['id'],
                'userId' => $user['id'],
                'status' => 'created',
                'createdTimeGreaterThan' => strtotime('-40 hours'),
            ), array('createdTime', 'DESC'), 0, 1
        );

        $order = current($oldOrders);

        if($course['price'] > 0 && $order && ($course['price'] == ($order['amount'] + $order['couponDiscount'])) ) {
             return $this->render('TopxiaWebBundle:CourseOrder:repay.html.twig', array(
                'order' => $order,
            ));
        }

        return $this->render('TopxiaWebBundle:CourseOrder:buy-modal.html.twig', array(
            'course' => $course,
            'user' => $userInfo,
            'avatarAlert' => AvatarAlert::alertJoinCourse($user),
            'courseSetting' => $courseSetting,
            'member' => $member,
            'userFields'=>$userFields,
            'payTypeChoices'=>$payTypeChoices,
            'account'=>$account,
            'amount'=>$amount,
            'ChargeCoin'=> $ChargeCoin
        ));
    }

    public function repayAction(Request $request)
    {
        $user = $this->getCurrentUser();
        if(!$user->isLogin()) {
            return $this->createMessageResponse('error', '用户未登录，不能支付。');
        }

        $order = $this->getOrderService()->getOrder($request->query->get('orderId'));


        if (empty($order)) {
            return $this->createMessageResponse('error', '订单不存在!');
        }

        if ((time() - $order['createdTime']) > 40 * 3600 ) {
            return $this->createMessageResponse('error', '订单已过期，不能支付，请重新创建订单。');
        }

        if($order["userId"] != $user["id"]){
            return $this->createMessageResponse('error', '不是您的订单，不能支付');
        }

        $course = $this->getCourseService()->getCourse($order['targetId']);
        if (empty($course)) {
            return $this->createMessageResponse('error', '购买的课程不存在，请重新创建订单!');
        }

        $result = $this->getOrderInfo($order["targetId"]);
        $result["order"] = $order;

        return $this->render('TopxiaWebBundle:Order:order-create.html.twig', $result);
    }

    private function getOrderInfo($id)
    {
        $course = $this->getCourseService()->getCourse($id);
        $userIds = array();
        $userIds = array_merge($userIds, $course['teacherIds']);
        $users = $this->getUserService()->findUsersByIds($userIds);

        $coinSetting = $this->getSettingService()->get("coin");
        
        $cashRate = 1;
        if(array_key_exists("cash_rate", $coinSetting)) {
            $cashRate = $coinSetting["cash_rate"];
        }

        $coursePriceShowType = "RMB";
        if(array_key_exists("coin_enabled", $coinSetting) 
            && $coinSetting["coin_enabled"] && array_key_exists("price_type", $coinSetting)) {
            $coursePriceShowType = $coinSetting["price_type"];
        }

        $user = $this->getCurrentUser();
        $account = $this->getCashAccountService()->getAccountByUserId($user["id"]);
        $accountCash = $account["cash"];

        $coinPayAmount = 0;
        $totalPrice = 0;

        $hasPayPassword = strlen($user['payPassword']) > 0;

        if($coursePriceShowType == "RMB") {
            $totalPrice = $course["price"];
            if($totalPrice*100 > $accountCash/$cashRate*100) {
                $coinPayAmount = $accountCash;
            } else {
                $coinPayAmount = $totalPrice*$cashRate;
            }
        } else if ($coursePriceShowType == "Coin") {
            $totalPrice = $course["coinPrice"];
            if($hasPayPassword && $totalPrice*100 > $accountCash*100) {
                $coinPayAmount = $accountCash;
            } else if($hasPayPassword) {
                $coinPayAmount = $totalPrice;
            }                
        } 

        $couponApp = $this->getAppService()->findInstallApp("Coupon");

        return array(
            'account' => $account,
            'couponApp' => $couponApp,
            'cashRate' => $cashRate,
            'hasPayPassword' => $hasPayPassword,
            'totalPrice' => $totalPrice,
            'coinPayAmount' => $coinPayAmount,
            'targetIds' => array($id),
            'targetTypes' => array("course"),
            'coursePriceShowType' => $coursePriceShowType,

            'courses' => empty($course) ? null : array($course),
            'users' => empty($users) ? null : $users,
            'payUrl' => 'course_order_pay',
        );
    }

    public function refundAction(Request $request , $id)
    {
        list($course, $member) = $this->getCourseService()->tryTakeCourse($id);
        $user = $this->getCurrentUser();

        if (empty($member) or empty($member['orderId'])) {
            throw $this->createAccessDeniedException('您不是课程的学员或尚未购买该课程，不能退学。');
        }

        $order = $this->getOrderService()->getOrder($member['orderId']);
        if (empty($order)) {
            throw $this->createNotFoundException();
        }

        if ('POST' == $request->getMethod()) {
            $data = $request->request->all();
            $reason = empty($data['reason']) ? array() : $data['reason'];
            $amount = empty($data['applyRefund']) ? 0 : null;

            $refund = $this->getCourseOrderService()->applyRefundOrder($member['orderId'], $amount, $reason, $this->container);

            return $this->createJsonResponse($refund);
        }

        $maxRefundDays = (int) $this->setting('refund.maxRefundDays', 0);
        $refundOverdue = (time() - $order['createdTime']) > ($maxRefundDays * 86400);

        return $this->render('TopxiaWebBundle:CourseOrder:refund-modal.html.twig', array(
            'course' => $course,
            'order' => $order,
            'maxRefundDays' => $maxRefundDays,
            'refundOverdue' => $refundOverdue,
        ));
    }

    public function cancelRefundAction(Request $request , $id)
    {
        $course = $this->getCourseService()->getCourse($id);
        if (empty($course)) {
            throw $this->createNotFoundException();
        }

        $user = $this->getCurrentUser();
        if (empty($user)) {
            throw $this->createAccessDeniedException();
        }

        $member = $this->getCourseService()->getCourseMember($course['id'], $user['id']);
        if (empty($member) or empty($member['orderId'])) {
            throw $this->createAccessDeniedException('您不是课程的学员或尚未购买该课程，不能取消退款。');
        }

        $this->getCourseOrderService()->cancelRefundOrder($member['orderId']);

        return $this->createJsonResponse(true);

    }

    public function getCourseService()
    {
        return $this->getServiceKernel()->createService('Course.CourseService');
    }

    public function getCourseOrderService()
    {
        return $this->getServiceKernel()->createService('Course.CourseOrderService');
    }

    protected function getSettingService()
    {
        return $this->getServiceKernel()->createService('System.SettingService');
    }

    protected function getOrderService()
    {
        return $this->getServiceKernel()->createService('Order.OrderService');
    }

    protected function getAuthService()
    {
        return $this->getServiceKernel()->createService('User.AuthService');
    }

    protected function getCashAccountService()
    {
        return $this->getServiceKernel()->createService('Cash.CashAccountService');
    }

    protected function getAppService()
    {
        return $this->getServiceKernel()->createService('CloudPlatform.AppService');
    }

    protected function getCouponService()
    {
        return $this->getServiceKernel()->createService('Coupon:Coupon.CouponService');
    }
}