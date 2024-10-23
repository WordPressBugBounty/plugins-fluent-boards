<style>
    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
        background: #F6F8FB;
    }
    .fbs_email_notification{
        padding: 40px 0px 0px 0px;
        align-items: center;
        gap: 10px;
        align-self: stretch;
    }
    .fbs_email_notification_top{
        align-items: center;
        margin-bottom: 30px;
    }
    .fbs_email_notification_head{
        text-align: center;
        margin-bottom: 30px;
    }
    .fbs_head_text{
        color: #170F49;
        font-size: 20px;
        font-style: normal;
        font-weight: 700;
        line-height: 1.2;
    }
    .fbs_email_notification_contents{
        padding: 30px;
        border-radius: 8px;
        background: #FFF;
        width: 60%;
        margin: auto;
    }
    .fbs_email_content{
        display: flex;
        background: #FFF;
    }
    .fbs_email_content_left{
        background: #FFF;
    }
    .fbs-avatar{
        width: 45px;
        height: 45px;
        border-radius: 50px;
    }
    .fbs_email_content_right{
        background: #FFF;
        width: 100%;
        margin-left: 10px;
    }
    .fbs_user_name{
        color: #1A1D1F;
        font-size: 15px;
        font-style: normal;
        font-weight: 600;
        line-height: 22px;
        background: #FFF;
    }
    .fbs_email_details{
        color:  #565865;
        font-size: 14px;
        font-style: normal;
        font-weight: 400;
        line-height: 20px;
        background: #FFF;
    }
    .fbs_invite_error{
        margin-top: 10px;
    }
    .fbs_email_comment{
        color: #2F3448;
        font-size: 15px;
        font-style: normal;
        font-weight: 500;
        line-height: 24px;
        margin-top: 10px;
        padding: 3px 10px;
    }
    .fbs_email_notification_bottom{
        text-align: center;
        margin-top: 30px;
    }
    .fbs_bottom_text_small{
        color: #2F3448;
        font-size: 14px;
        font-style: normal;
        font-weight: 400;
        line-height: 20px;
    }
    .fbs_bottom_text{
        color: #2F3448;
        text-align: center;
        font-size: 20px;
        font-style: normal;
        font-weight: 600;
        line-height: 28px; /* 140% */
    }
    .fbs_email_notification_footer{
        display: flex;
        height: 100%;
        padding: 30px 0;
        align-items: flex-start;
        gap: 8px;
        align-self: stretch;
        background: #FFF;
    }
    .fbs_email_footer_text{
        color: #2F3448;
        font-size: 16px;
        font-style: normal;
        font-weight: 500;
        line-height: 22px;
        margin: auto;
    }
    .fbs_invitation_button{
        background: #6268F1FF;
        color: #FFF;
        font-size: 16px;
        font-weight: 600;
        line-height: 22px;
        padding: 10px 20px;
        border-radius: 8px;
        text-align: center;
        text-decoration: none;
        margin-top: 10px;
        width: 170px;
    }

    .fbs_daily_reminder {
        margin: 0 auto;
        display:block;
        background: #FFF;
        border-radius: 8px;
    }
    .fbs_email_greeting {
        background-color: #ffffff;
        margin-bottom: 20px;
        font-size: 15px;
    }
    .fbs_email_task_list_group {
        background-color: #ffffff;
        margin-bottom: 20px;
        width: 100%;
        margin-top: 10px;
        gap: 10px;
    }
    .fbs_email_task_list_item {
        color: #2F3448;
        margin-bottom: 5px;
        border-radius: 8px;
        padding: 10px;
        text-decoration: none;
        list-style: none;
    }
    .fbs_bg_white {
        background-color: #ffffff !important;
    }

    @media (max-width: 600px) {
        .fbs_email_content {
            display: block;
        }
        .fbs_email_content_right {
            margin-left: 0;
            margin-top: 10px;
        }
        .fbs_email_notification_contents {
            width: 90%;
        }
    }

</style>