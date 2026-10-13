<?php  
session_start(); 

// Include PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load Composer's autoloader
require '../../PhpMailer/vendor/autoload.php';

// Shared SMTP credentials (keeps the SendGrid key out of this file)
require 'SendGrid_config.php';

// Shared SQL Server credentials (keeps the DB password out of this file)
require '../SQL_ServerCredentials.php';

try {
    // TEST VERSION — everything now reads from SQL Server (HSSB) instead of the
    // MySQL mirror, since HSSB now carries every table this report needs.
    $sqlsrvConn = new PDO("sqlsrv:server=$serverName;Database=" . $connectionOptions['Database'] . ";LoginTimeout=15", $connectionOptions['Uid'], $connectionOptions['PWD']);
    $sqlsrvConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // This report goes out every Tuesday — only courses whose status changed
    // since last Tuesday (through yesterday) are relevant, not the full list.
    $courseWeekStart = date('Y-m-d', strtotime('last Tuesday')) . ' 00:00:00';
    $courseWeekEnd   = date('Y-m-d', strtotime('yesterday')) . ' 23:59:59';

    $courseStmt = $sqlsrvConn->prepare(
        "SELECT CourseName, Status, CourseInstanceCreationDate
         FROM [HSSB].[dbo].[courses_getallcourses]
         WHERE CourseInstanceCreationDate BETWEEN :weekStart AND :weekEnd
         ORDER BY Status, CourseName"
    );
    $courseStmt->execute([':weekStart' => $courseWeekStart, ':weekEnd' => $courseWeekEnd]);
    $courseRows = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch regions and managers
    // NOTE: the SQL Server `regions` table has a different shape than the old
    // MySQL one (id/name/email/active instead of RegionID/Region_Name/Rgn_Mgr/
    // Status), and there's no `users` login table on this side — the manager's
    // name is looked up from users_getallusers by matching their email instead.
    $sql = "SELECT 
                users_getallusers.First_Name AS first_name,
                users_getallusers.Last_Name  AS last_name,
                regions.name  AS Region_Name,
                regions.id    AS RegionID,
                regions.email AS Rgn_Mgr
            FROM [HSSB].[dbo].[regions]
            INNER JOIN [HSSB].[dbo].[group_list] ON regions.id = group_list.RegionID
            INNER JOIN [HSSB].[dbo].[users_getallusers] ON regions.email = users_getallusers.Email_Address
            WHERE regions.active = 'Y'
            GROUP BY users_getallusers.First_Name, users_getallusers.Last_Name, regions.name, regions.id, regions.email";

    $stmt = $sqlsrvConn->prepare($sql);
    $stmt->execute();
    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo "Database connection failed: " . $e->getMessage();
    exit;
}

// Course Active/Inactive table — built once, same for every recipient
$courseStatusTable = '<h3 style="margin-top:30px;">Course Status Changes (' 
    . date('M j', strtotime($courseWeekStart)) . ' - ' . date('M j, Y', strtotime($courseWeekEnd)) . ')</h3>';

if (empty($courseRows)) {
    $courseStatusTable .= '<p style="color:#666;">No course status changes this week.</p>';
} else {
    $courseStatusTable .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">
        <thead>
            <tr style="background-color:#222; color:#fff;">
                <th>Course Name</th>
                <th>Status</th>
                <th>Active/Inactive Date</th>
            </tr>
        </thead>
        <tbody>';
    foreach ($courseRows as $course) {
        $statusColor = ($course['Status'] === 'Active') ? '#28a745' : '#dc3545';
        $courseDate  = !empty($course['CourseInstanceCreationDate'])
            ? date('M j, Y', strtotime($course['CourseInstanceCreationDate']))
            : '&#8212;';
        $courseStatusTable .= '<tr>
                <td>' . htmlspecialchars($course['CourseName']) . '</td>
                <td style="color:' . $statusColor . '; font-weight:bold;">' . htmlspecialchars($course['Status']) . '</td>
                <td align="center">' . $courseDate . '</td>
            </tr>';
    }
    $courseStatusTable .= '</tbody></table>';
}

// Create PHPMailer instance
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUsername;
    $mail->Password = $smtpPassword;
    $mail->SMTPSecure = $smtpSecure;
    $mail->Port = $smtpPort;

    foreach ($recipients as $recipient) {
        // Get the RegionID and email for the regional manager
        $RegionID = $recipient['RegionID'];
        $emailAddress = $recipient['Rgn_Mgr'];
        $First_Name = $recipient['first_name'];
        $Last_NameRM = $recipient['last_name'];
        $Region_Name = $recipient['Region_Name'];

        // Fetch the enrollments data for the specific region
        $sql = "SELECT 
                    group_list.BranchName,
                    users_getallusers.First_Name,
                    users_getallusers.Last_Name,
                    users_getallusers.User_Name,                    
                    count(*) AS Total,
                    sum(case when Enrollment_Status = 'Complete' then 1 else 0 end) AS Complete,
                    sum(case when Enrollment_Status = 'Active' then 1 else 0 end) AS Active    
                FROM [HSSB].[dbo].[CourseEnrollments_GetUserCourseEnrollments]
                INNER JOIN [HSSB].[dbo].[users_getallusers] ON CourseEnrollments_GetUserCourseEnrollments.User_Id = users_getallusers.User_Id
                INNER JOIN [HSSB].[dbo].[users_groupsforusers] ON users_getallusers.User_Id = users_groupsforusers.UserID
                INNER JOIN [HSSB].[dbo].[group_list] ON users_groupsforusers.GroupID = group_list.BranchID
                WHERE RegionID = ? and group_list.Branch = '1' and Enrollment_Status != 'Terminated'
                GROUP BY group_list.BranchName, users_getallusers.First_Name, users_getallusers.Last_Name, users_getallusers.User_Name
                ORDER BY group_list.BranchName";

        $stmt = $sqlsrvConn->prepare($sql);
        $stmt->execute([$RegionID]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        //////////
        // Fetch Regional Manager Training Status
        $sql_mgr = "SELECT 
                        count(*) AS Total,
                        sum(case when Enrollment_Status = 'Complete' then 1 else 0 end) AS Complete,
                        sum(case when Enrollment_Status = 'Active' then 1 else 0 end) AS Active    
                    FROM [HSSB].[dbo].[CourseEnrollments_GetUserCourseEnrollments]
                    INNER JOIN [HSSB].[dbo].[users_getallusers] ON CourseEnrollments_GetUserCourseEnrollments.User_Id = users_getallusers.User_Id
                    WHERE users_getallusers.User_Name = ? 
                      AND Enrollment_Status != 'Terminated'";

        $stmt_mgr = $sqlsrvConn->prepare($sql_mgr);
        $stmt_mgr->execute([$emailAddress]);
        $mgr_data = $stmt_mgr->fetch(PDO::FETCH_ASSOC);

        // Extract training status data
        $mgrTotalEnrollments = $mgr_data['Total'] ?? 0;
        $mgrActiveCount = $mgr_data['Active'] ?? 0;
        $mgrCompleteCount = $mgr_data['Complete'] ?? 0;

        // Calculate percentages
        $mgrActivePercentage = $mgrTotalEnrollments > 0 ? round(($mgrActiveCount / $mgrTotalEnrollments) * 100, 2) . '%' : '0%';
        $mgrCompletePercentage = $mgrTotalEnrollments > 0 ? round(($mgrCompleteCount / $mgrTotalEnrollments) * 100, 2) . '%' : '0%';


        ////////

        // Count enrollments
        $totalEnrollments = array_sum(array_column($data, 'Total'));
        $activeCount = array_sum(array_column($data, 'Active'));
        $completeCount = array_sum(array_column($data, 'Complete'));

        // Calculate percentages
        $activePercentage = $totalEnrollments > 0 ? round(($activeCount / $totalEnrollments) * 100, 2) . '%' : '0%';
        $completePercentage = $totalEnrollments > 0 ? round(($completeCount / $totalEnrollments) * 100, 2) . '%' : '0%';

        // Prepare the email content
        $mail->isHTML(true);
        $mail->Subject = 'Training Enrollment Report for Your Region: ' . htmlspecialchars($Region_Name);
        $mail->setFrom('no-reply@hirsch.com', 'HPSNet');
        $mail->clearAllRecipients();  // Clear To, CC, BCC between iterations
       // $mail->addAddress($emailAddress);  // Add the specific regional manager
       // $mail->addBCC('arehman@hirsch.com');  // Add CC
       // $mail->addBCC('aking@hirsch.com');      // Add another CC
        $mail->addBCC('sajith.nanayakkara@hirsch.com'); 
        //$mail->addCC('klehman@hirsch.com'); 
        //$mail->addCC('sshipley@hirsch.com'); 
       // $mail->addCC('skuruvilla@hirsch.com');   
       // $mail->addBCC('eproano@hirsch.com');   
        

        // Email body
        $body = '<p>Hello ' . htmlspecialchars($First_Name) . ',</p>';
        $body .= '<p>This email contains your region training enrollment details for the last Six months in ' . htmlspecialchars($Region_Name) . '.</p>';
        //$body .= '<p>This email contains your region training enrollment details for ' . htmlspecialchars($Region_Name) . '. </p>';
        $body .= '<p><a href="http://hpsnet.hirsch.local/dist/pages/hpforms/redirect.php?url=%2Fdist%2Fpages%2Fhpforms%2FBlueVoltTrainingSummary.php" 
        style="background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">
        Click here to View BlueVolt Training Detail</a></p>';
        
        // Initialize counters for total enrollments
        $totalRegionTotal = 0;
        $totalRegionComplete = 0;
        $totalRegionActive = 0;

        // Group data by Branch Name
        $groupedData = [];

        foreach ($data as $row) {
            $branch = $row['BranchName'];
            if (!isset($groupedData[$branch])) {
                $groupedData[$branch] = [];
            }
            $groupedData[$branch][] = $row;
        }

        // Generate the table for each branch
        $body .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';

        foreach ($groupedData as $branchName => $entries) {
            // Calculate Complete Percentage for each branch
            $branchTotal = 0;
            $branchActive = 0;
            $branchComplete = 0;

            foreach ($entries as $row) {
                $branchTotal += $row['Total'];
                $branchActive += $row['Active'];
                $branchComplete += $row['Complete'];
            }

            // Calculate branch complete percentage
            $branchCompletePercentage = $branchTotal > 0 ? round(($branchComplete / $branchTotal) * 100) . '%' : '0%';

            // Branch Header with complete percentage
            $body .= '<tr style="background-color:#222; color:#fff; font-weight:bold;">
                          <td colspan="7">' . htmlspecialchars($branchName) . ' </td>
                      </tr>';

            // Table Headers
            $body .= '<thead>
                        <tr>
                            <th>First Name</th>
                            <th>Last Name</th>
                            <th>User Name</th>
                            <th>Total Enrolled</th>
                            <th>Active</th>
                            <th>Complete</th>
                            
                        </tr>
                      </thead>
                      <tbody>';

            // Table Data
            foreach ($entries as $row) {
                $body .= '<tr>
                             <td>' . htmlspecialchars($row['First_Name']) . '</td>
                             <td>' . htmlspecialchars($row['Last_Name']) . '</td>
                             <td>' . htmlspecialchars($row['User_Name']) . '</td>
                             <td align="center">' . $row['Total'] . '</td>
                             <td align="center">' . $row['Active'] . '</td>
                             <td align="center">' . $row['Complete'] . '</td>
                           
                          </tr>';
            }

            // Add branch totals
            $body .= '<tr style="background-color:#e9ecef;">
                         <td colspan="3"  style="color: #28a745; font-weight: bold;"><strong>Total for ' . htmlspecialchars($branchName) . ' - ( ' . $branchCompletePercentage . ')</strong></td>
                         <td align="center"  style="color: #28a745; font-weight: bold;">' . $branchTotal . '</td>
                         <td align="center"  style="color: #28a745; font-weight: bold;">' . $branchActive . '</td>
                         <td align="center"  style="color: #28a745; font-weight: bold;">' . $branchComplete . '</td>
                      </tr>';

            // Add to region totals
            $totalRegionTotal += $branchTotal;
            $totalRegionActive += $branchActive;
            $totalRegionComplete += $branchComplete;

        }

        $body .= '</tbody></table>';

        // Calculate region-level complete percentage
        $regionCompletePercentage = $totalRegionTotal > 0 ? round(($totalRegionComplete / $totalRegionTotal) * 100, 2) . '%' : '0%';

        
        
        // Add the overall region total and percentage at the bottom


        // Regional Manager Training Status Table 
        $body .= '<h3>' . htmlspecialchars($First_Name) . ' ' . htmlspecialchars($Last_NameRM) . ' - Regional Manager Training Status </h3>';
        $body .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
        $body .= '<tr style="background-color:#222; color:#fff; font-weight:bold;">
                    <th>Total Enrolled</th>
                    <th>Active</th>
                    <th>Complete</th>
                  </tr>';
        $body .= '<tr>
                    <td align="center">' . $mgrTotalEnrollments . '</td>
                    <td align="center">' . $mgrActiveCount . ' (' . $mgrActivePercentage . ')</td>
                    <td align="center">' . $mgrCompleteCount . ' (' . $mgrCompletePercentage . ')</td>
                  </tr>';
        $body .= '</table>';

        $body .= $courseStatusTable;

        $mail->Body = $body;
        $mail->send();
    }

    echo 'Emails sent successfully.';

} catch (Exception $e) {
    echo "Email sending failed: " . $mail->ErrorInfo;
}
?>
